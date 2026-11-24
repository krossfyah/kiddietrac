<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * SALES CAMPAIGNS (2026-10-26, Anthony: "create a sales campaign section to reach out to prospects/leads ... introducing
 * kiddietrac and our services/products and add the quick intro video - make this email look good and the campaign
 * function highly usable to track emails, leads and follow-up if someone responds and adds to leads").
 *
 * One introduction email per prospect, sent from sales@ (X-KT-Send-As, like booking confirmations), with:
 *   - the intro film as a poster that opens the film page (email cannot play video);
 *   - opens (a pixel) and clicks (fixed link keys -> fixed KiddieTrac URLs, never an open redirect) per person;
 *   - responses: "Yes, send me more information" (a confirm page, so link scanners cannot answer for them), booking a
 *     demo on the website (matched by email), replying (seen in the connected sales mailbox, or marked by hand) and
 *     unsubscribing (CASL: honoured immediately, kept in sales_suppressions for every future campaign).
 * A response puts the person in Leads (found by email or created), adds a follow-up task for the next working day and
 * emails the sales team - the same people a new lead goes to.
 */
final class SalesCampaigns
{
    public const FROM = 'sales@kiddietrac.com';
    public const ADDRESS = 'KiddieTrac · 246372 Hockley Road, Mono, ON L9W 6K4, Canada';
    public const PHONE = '1-855-400-9996';
    private const API = 'https://api.kiddietrac.com/api/v1';
    private const SITE = 'https://www.kiddietrac.com';

    /** Films a campaign can lead with: slug => [label, film page]. The poster is /videos/{slug}/og.jpg. */
    public const VIDEOS = [
        'a-day-with-kiddietrac' => ['A Day with KiddieTrac (1-minute tour)', '/watch/a-day-with-kiddietrac/'],
        'safe-arrival' => ['Safe Arrival', '/watch/safe-arrival/'],
        'one-tap' => ['One-Tap check-in', '/watch/one-tap/'],
        'walk-watch' => ['Walk Watch', '/watch/walk-watch/'],
    ];

    /** The signature tools, always shown with their own marks: key => [mark, name, tagline, line]. */
    public const BRANDS = [
        'onetap' => ['mark-one-tap.png', 'One-Tap', 'Check-in in one tap', 'Parents scan your centre’s daily code from their own phone. The educator sees who is dropping off or picking up, with their photo, and families and the room are told at once.'],
        'safearrival' => ['mark-safe-arrival.png', 'Safe Arrival', 'Every child, accounted for', 'If a child booked in today hasn’t arrived and no absence was reported, parents are asked right away and the room and director are alerted. Every check is recorded.'],
        'walkwatch' => ['mark-walk-watch.png', 'Walk Watch', 'Live GPS walks & outings', 'Educators share a live map on every walk. Each parent is told when the group sets off and when it’s back, and the director sees who is out and where.'],
    ];

    /** What else a campaign can highlight (cards): key => [icon, title, line]. */
    public const HIGHLIGHTS = [
        'billing' => ['💳', 'Billing & subsidies, done for you', 'Invoices, autopay and subsidy programs handled for you, with QuickBooks, Xero or Sage sync.'],
        'parentapp' => ['💬', 'A parent app families love', 'Photos, daily reports and two-way messages in their own app, in their language.'],
        'compliance' => ['📋', 'Inspection-ready records', 'Incident reports, e-signed forms, immunizations, ratios and a full audit trail.'],
        'staff' => ['🧑‍🏫', 'Staff, time clock & payroll', 'Scheduling, timesheets, certifications and payroll documents in one place.'],
        'reports' => ['📊', 'Reports that answer questions', 'Attendance, enrolment and revenue at a glance, for one centre or many.'],
        'whitelabel' => ['🏷️', 'Your own brand', 'An optional parent app and portal under your centre’s name.'],
    ];
    public const DEFAULT_HIGHLIGHTS = ['billing', 'parentapp', 'compliance', 'staff'];

    /** CASL consent basis: key => [what the sales team picks, the line the recipient sees]. */
    public const CONSENT = [
        'express' => ['Express consent: they asked to hear from us', 'You’re receiving this because you asked to hear from KiddieTrac.'],
        'implied_inquiry' => ['Implied: they contacted us or asked about our services (last 6 months)', 'You’re receiving this because you recently got in touch with KiddieTrac.'],
        'implied_business' => ['Implied: existing business relationship (last 2 years)', 'You’re receiving this because of your recent business with KiddieTrac.'],
        'implied_published' => ['Implied: their business email is published for enquiries and this is relevant to their role', 'You’re receiving this because your business email is published for enquiries about your childcare program.'],
    ];

    /** Fixed link keys -> fixed destinations (tracked, never an open redirect). */
    private static function linkTarget(string $key, object $c): ?string
    {
        $video = self::VIDEOS[$c->video] ?? self::VIDEOS['a-day-with-kiddietrac'];
        return [
            'video' => self::SITE . $video[1],
            'demo' => self::SITE . '/?book=1',
            'plans' => self::SITE . '/pricing',
            'site' => self::SITE . '/',
        ][$key] ?? CampaignRich::target($c, $key);   // a link written in the rich body: "xa", "xb"... (2026-11-04)
    }

    public static function token(): string
    {
        return Str::lower(Str::random(40));
    }

    private static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function firstName(?string $name): string
    {
        $f = trim((string) strtok(trim((string) $name), ' '));
        return $f !== '' ? $f : 'there';
    }

    /** The sign-off: a FIRST name only. No email ever shows a KiddieTrac person's last name (Anthony, 2026-10-26). */
    public static function senderName(?int $userId): string
    {
        $u = $userId ? DB::table('users')->where('id', $userId)->first(['first_name']) : null;
        $n = $u ? trim((string) $u->first_name) : '';
        return $n !== '' ? $n . ' from KiddieTrac' : 'The KiddieTrac team';
    }

    /**
     * The email. $r is the recipient (null = a preview or a test: links work but nothing is tracked or counted).
     * No prices (Anthony, 2026-10-26): the email sells the meeting, the meeting sells the plan.
     */
    public static function render(object $c, ?object $r, ?int $senderId = null): string
    {
        // An alternate design chosen for this campaign (2026-10-30): drawn by CampaignDesigns, same tracking and CASL rules.
        $custom = empty($c->template) && ($c->design ?? null) === 'custom';   // "Your own email" (2026-11-04): the rich body is the whole email
        if ($custom) return self::renderCanvas($c, $r, $senderId);           // with its own header, footer and look (2026-11-05)
        if (empty($c->template) && ! empty($c->design ?? null) && CampaignDesigns::has($c->design)) {
            return CampaignDesigns::render($c, $r, $senderId);
        }
        /* A SUBSCRIBER EMAIL (2026-10-27): a campaign with a template is one of the KiddieTrac subscriber-list emails
           (App\\Support\\SubscriberCampaign) - its own design, the recipient's tracked unsubscribe link, the open pixel. */
        if (! empty($c->template)) {
            $tok = $r ? $r->token : null;
            $html = SubscriberCampaign::render((string) $c->template, $r ? (string) $r->email : 'preview@kiddietrac.com', $r ? (string) $r->name : '',
                $tok ? self::API . '/c/u/' . $tok : null);
            $pixel = $tok ? '<img src="' . self::API . '/c/o/' . $tok . '.gif" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;">' : '';
            return $pixel ? preg_replace('#</body>#', $pixel . '</body>', $html, 1) : $html;
        }
        $tok = $r ? $r->token : null;
        $link = fn (string $k) => $tok ? self::API . '/c/l/' . $tok . '/' . $k : self::linkTarget($k, $c);
        $interested = $tok ? self::API . '/c/i/' . $tok : self::SITE . '/contact';
        $unsub = $tok ? self::API . '/c/u/' . $tok : self::SITE . '/contact';
        $video = self::VIDEOS[$c->video] ?? self::VIDEOS['a-day-with-kiddietrac'];
        $poster = self::SITE . '/videos/' . (isset(self::VIDEOS[$c->video]) ? $c->video : 'a-day-with-kiddietrac') . '/og-play.jpg';   // the thumbnail with a play button (2026-10-27)
        $marks = self::SITE . '/email-icons/run-up/';
        $first = self::firstName($r ? $r->name : 'Alex Sample');
        $consent = self::CONSENT[$r->consent_basis ?? $c->consent_basis] ?? self::CONSENT['implied_published'];
        $hl = json_decode((string) $c->highlights, true);
        $hl = array_values(array_filter(is_array($hl) && $hl ? $hl : self::DEFAULT_HIGHLIGHTS, fn ($k) => isset(self::HIGHLIGHTS[$k])));
        if (! $hl) $hl = self::DEFAULT_HIGHLIGHTS;
        $headline = trim((string) $c->headline) !== '' ? $c->headline : ($custom ? '' : 'Less paperwork. Safer days. Happier families.');
        $intro = trim((string) $c->intro) !== '' || $custom ? (string) $c->intro
            : "I’m reaching out from KiddieTrac, the childcare management platform centres and agencies across North America use to run their day: check-in, attendance, billing, parent communication and compliance, all in one place.\n\nMost directors tell us the same thing: too much of the day goes to paperwork and chasing information. KiddieTrac gives that time back to your team and the children. The one-minute film below shows a full day in KiddieTrac, from drop-off to pick-up.";
        $paras = array_filter(array_map('trim', preg_split("/\n\s*\n/", (string) $intro)));
        $navy = '#0B1F4D'; $teal = '#0E8F99'; $ink = '#1E293B'; $muted = '#64748B';
        $font = 'Segoe UI,Helvetica,Arial,sans-serif';
        $p = fn (string $html, string $extra = '') => '<p style="margin:0 0 14px;font:400 15.5px/1.62 ' . $font . ';color:' . $ink . ';' . $extra . '">' . $html . '</p>';
        $h2 = fn (string $t) => '<div class="hd" style="font:800 19px/1.3 ' . $font . ';color:' . $navy . ';margin:0 0 6px;">' . self::e($t) . '</div>';
        $kick = fn (string $t) => '<div class="kick" style="font:800 11px/1 ' . $font . ';letter-spacing:1.8px;color:' . $teal . ';text-transform:uppercase;margin:0 0 10px;">' . self::e($t) . '</div>';
        $demoBtn = fn (string $label) => '<table role="presentation" cellpadding="0" cellspacing="0" align="center"><tr><td bgcolor="' . $teal . '" style="border-radius:12px;background:' . $teal . ';background-image:linear-gradient(135deg,#0E8F99,#1F6080);">'
            . '<a href="' . self::e($link('demo')) . '" style="display:inline-block;padding:15px 30px;font:800 16px/1 ' . $font . ';color:#ffffff;text-decoration:none;border-radius:12px;">' . $label . '</a></td></tr></table>';

        // The signature tools, with their marks.
        $brands = '';
        foreach (self::BRANDS as [$mark, $name, $tag, $line]) {
            $brands .= '<tr><td style="padding:0 0 12px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="card" style="background:#F4F8FB;border:1px solid #E3EBF2;border-radius:16px;"><tr>'
                . '<td width="76" valign="top" style="padding:16px 0 16px 16px;"><img src="' . self::e($marks . $mark) . '" width="56" height="56" alt="' . self::e($name) . '" style="display:block;border:0;width:56px;height:56px;border-radius:14px;"></td>'
                . '<td valign="top" style="padding:15px 18px 15px 14px;">'
                . '<div style="font:800 16px/1.3 ' . $font . ';color:' . $navy . ';">' . self::e($name) . ' <span style="font-weight:700;font-size:13px;color:' . $teal . ';">· ' . self::e($tag) . '</span></div>'
                . '<div style="font:400 14px/1.55 ' . $font . ';color:' . $muted . ';margin-top:4px;">' . self::e($line) . '</div></td></tr></table></td></tr>';
        }

        // Everything else, as cards.
        $cells = '';
        foreach (array_chunk($hl, 2) as $pair) {
            $cells .= '<tr>';
            foreach ($pair as $i => $k) {
                [$ic, $t, $line] = self::HIGHLIGHTS[$k];
                $cells .= '<td width="50%" valign="top" style="padding:' . ($i ? '0 0 12px 6px' : '0 6px 12px 0') . ';">'
                    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="card2" style="border:1px solid #E3EBF2;border-radius:14px;"><tr><td style="padding:14px 15px;">'
                    . '<div style="font-size:20px;line-height:1;margin-bottom:7px;">' . $ic . '</div>'
                    . '<div style="font:800 14.5px/1.3 ' . $font . ';color:' . $navy . ';margin-bottom:3px;">' . self::e($t) . '</div>'
                    . '<div style="font:400 13px/1.5 ' . $font . ';color:' . $muted . ';">' . self::e($line) . '</div>'
                    . '</td></tr></table></td>';
            }
            if (count($pair) === 1) $cells .= '<td width="50%"></td>';
            $cells .= '</tr>';
        }

        // Why centres switch.
        $why = '';
        foreach ([
            ['Hours back every week', 'Attendance, billing, daily reports and forms stop being after-hours paperwork.'],
            ['Families who feel part of the day', 'Photos, updates and messages arrive in their own app, as they happen.'],
            ['Live in days, not months', 'We help move your existing records over and train your team.'],
            ['Real people when you need them', 'Support from a team that knows childcare, not a ticket queue.'],
        ] as [$t, $line]) {
            $why .= '<tr><td width="30" valign="top" style="padding:2px 0 12px;"><div class="tick" style="width:22px;height:22px;border-radius:11px;background:#DCFCE7;color:#15803D;font:800 13px/22px ' . $font . ';text-align:center;">✓</div></td>'
                . '<td valign="top" style="padding:0 0 12px 8px;font:400 14.5px/1.55 ' . $font . ';color:' . $ink . ';"><b style="color:' . $navy . ';">' . self::e($t) . '.</b> ' . self::e($line) . '</td></tr>';
        }

        // What happens on the demo.
        $steps = '';
        foreach ([['1', 'We listen', 'How your centre runs today and what slows your team down.'],
                  ['2', 'You see it working', 'KiddieTrac with your own day in mind: check-in, families, billing, compliance.'],
                  ['3', 'You leave with a plan', 'Clear next steps and a proposal for your centres. No obligation.']] as [$n, $t, $line]) {
            $steps .= '<td width="33%" valign="top" style="padding:0 6px;"><div class="num" style="width:30px;height:30px;border-radius:15px;background:#ffffff;color:' . $navy . ';font:800 15px/30px ' . $font . ';text-align:center;margin:0 auto 8px;">' . $n . '</div>'
                . '<div style="font:800 14px/1.3 ' . $font . ';color:#ffffff;text-align:center;">' . self::e($t) . '</div>'
                . '<div class="sub" style="font:400 12.5px/1.5 ' . $font . ';color:#C9D6F2;text-align:center;margin-top:4px;">' . self::e($line) . '</div></td>';
        }

        $pixel = $tok ? '<img src="' . self::API . '/c/o/' . $tok . '.gif" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;">' : '';
        $pre = trim((string) $c->preheader) !== '' ? $c->preheader : 'See a full childcare day in KiddieTrac in one minute, then let’s find 20 minutes to talk.';
        $sender = self::senderName($senderId);

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark"><title>' . self::e($c->subject) . '</title>'
            // Dark mode (2026-10-27): a real dark palette instead of a glaring white card or an auto-inverted one.
            . '<style>@media (prefers-color-scheme:dark){body.bg,table.bg,td.bg{background:#0B1220 !important}table.sheet{background:#121B2E !important;box-shadow:none !important}.sheet p,.sheet td,.sheet div,.sheet span{color:#CBD5E1 !important}.sheet h1,.sheet b,.sheet .hd{color:#F1F5F9 !important}.sheet .kick{color:#5FD3DB !important}.sheet table.card{background:#18233A !important;border-color:#26344F !important}.sheet table.card2{border-color:#26344F !important}.sheet .keep td,.sheet .keep div{color:#FFFFFF !important}.sheet .keep .sub{color:#C9D6F2 !important}.sheet .keep .num{color:#0B1F4D !important}.sheet .pslink{border-color:#26344F !important}.foot,.foot a{color:#8A97AB !important}.sheet .tick{color:#15803D !important;background:#DCFCE7 !important}}@media (max-width:620px){table.sheet,table.fine{width:100% !important}td.bg{padding:12px 8px !important}}</style></head>'
            . '<body class="bg" style="margin:0;padding:0;background:#EEF2F7;-webkit-text-size-adjust:100%;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . self::e($pre) . str_repeat('&#8199;&#847; ', 30) . '</div>'
            . '<table role="presentation" class="bg" width="100%" cellpadding="0" cellspacing="0" bgcolor="#EEF2F7"><tr><td class="bg" align="center" style="padding:28px 12px;">'
            . '<table role="presentation" class="sheet" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 10px 40px rgba(11,31,77,.10);">'
            . '<tr><td bgcolor="#081C41" style="background:#081C41;padding:24px 32px 22px;" align="center"><img src="https://api.kiddietrac.com/email/wordmark-tagline-navy.png" width="200" alt="KiddieTrac" style="display:block;border:0;width:200px;max-width:60%;height:auto;"></td></tr>'
            . '<tr><td height="5" style="height:5px;line-height:5px;font-size:0;background:' . $teal . ';background-image:linear-gradient(90deg,#1DB9C3,#6DB33F);">&nbsp;</td></tr>'
            // opening
            . '<tr><td style="padding:34px 36px 4px;">' . ($custom ? '' : $kick('Hello from KiddieTrac'))
            . ($headline !== '' ? '<h1 style="margin:0 0 18px;font:800 27px/1.22 ' . $font . ';color:' . $navy . ';">' . self::e($headline) . '</h1>' : '')
            . ($custom ? '' : $p('Hi ' . self::e($first) . ','))
            . (CampaignRich::isRich($intro) ? CampaignRich::html($intro, self::richStyles($font, $ink, $navy, $teal), $tok, $first)
                : implode('', array_map(fn ($x) => $p(nl2br(self::e($x))), $paras)))
            . '</td></tr>'
            . ($custom ? '' : ''   // the standard sections; "Your own email" has only what was written
            // the film
            . '<tr><td style="padding:6px 36px 4px;"><a href="' . self::e($link('video')) . '" style="text-decoration:none;display:block;">'
            . '<img src="' . self::e($poster) . '" width="528" alt="▶ Watch ' . self::e($video[0]) . '" style="display:block;width:100%;max-width:528px;height:auto;border:0;border-radius:14px;"></a>'
            . '<div style="text-align:center;margin:10px 0 0;font:600 13px/1.4 ' . $font . ';color:' . $muted . ';">▶ <a href="' . self::e($link('video')) . '" style="color:' . $teal . ';font-weight:700;text-decoration:none;">Watch ' . self::e($video[0]) . '</a></div></td></tr>'
            // early ask
            . '<tr><td align="center" style="padding:22px 36px 8px;">' . $demoBtn('📅 Book a 20-minute demo')
            . '<div style="font:400 13px/1.5 ' . $font . ';color:' . $muted . ';margin-top:10px;">Pick a time that suits you. It takes less than a minute.</div></td></tr>'
            // signature tools
            . '<tr><td style="padding:26px 36px 4px;">' . $kick('Only in KiddieTrac') . $h2('Three tools directors tell us they can’t do without')
            . $p('Built in-house and included in KiddieTrac, so the moments that matter most are covered without extra apps or paper.', 'color:' . $muted . ';font-size:14.5px;')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $brands . '</table></td></tr>'
            // everything else
            . '<tr><td style="padding:14px 36px 2px;">' . $h2('Plus everything your team needs, in one place')
            . '<div style="height:8px;line-height:8px;font-size:0;">&nbsp;</div><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $cells . '</table></td></tr>'
            // why switch
            . '<tr><td style="padding:18px 36px 6px;">' . $h2('Why centres switch to KiddieTrac') . '<div style="height:8px;line-height:8px;font-size:0;">&nbsp;</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $why . '</table></td></tr>'
            // the demo
            . '<tr><td style="padding:16px 36px 6px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="' . $navy . '" class="keep" style="background:' . $navy . ';border-radius:18px;"><tr><td style="padding:24px 18px 26px;">'
            . '<div style="font:800 11px/1 ' . $font . ';letter-spacing:1.8px;color:#7FE3EA;text-transform:uppercase;text-align:center;margin-bottom:8px;">20 minutes, no obligation</div>'
            . '<div style="font:800 21px/1.3 ' . $font . ';color:#ffffff;text-align:center;margin-bottom:18px;">What happens on the demo</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' . $steps . '</tr></table>'
            . '<div style="height:22px;line-height:22px;font-size:0;">&nbsp;</div>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" align="center"><tr><td bgcolor="#ffffff" style="border-radius:12px;background:#ffffff;">'
            . '<a href="' . self::e($link('demo')) . '" style="display:inline-block;padding:15px 30px;font:800 16px/1 ' . $font . ';color:' . $navy . ';text-decoration:none;border-radius:12px;">📅 Book my demo</a></td></tr></table>'
            . '</td></tr></table></td></tr>'
            // other ways to reach out
            . '<tr><td align="center" style="padding:18px 36px 4px;">'
            . '<a href="' . self::e($interested) . '" style="display:inline-block;padding:11px 22px;border:2px solid ' . $teal . ';border-radius:12px;font:700 14px/1 ' . $font . ';color:' . $teal . ';text-decoration:none;">Not ready for a demo? Send me more information</a>'
            . '<div style="font:400 13.5px/1.6 ' . $font . ';color:' . $muted . ';margin-top:12px;">Or simply reply to this email with a good time to talk, or call us at <b style="color:' . $ink . ';">' . self::PHONE . '</b>.</div></td></tr>'
            // The app and the Google review (2026-10-27)
            . '<tr><td style="padding:22px 36px 0;">' . PlayBadge::card('font-family:' . $font . ';') . '</td></tr>'
            )
            // sign-off
            . '<tr><td style="padding:22px 36px 30px;">'
            . ($custom ? '' : $p('Thank you for everything you do for the families in your care. I’d love to show you how KiddieTrac can make your days easier.'))
            // The sign-off is optional in every layout (2026-11-05): 🎨 Signature, canvas.signature.
            . (CampaignRich::canvas($c->canvas ?? null)['signature'] ? '<p style="margin:14px 0 0;font:400 15px/1.55 ' . $font . ';color:' . $ink . ';">Talk soon,<br><b>' . self::e($sender) . '</b><br>'
            . '<span style="color:' . $muted . ';font-size:13.5px;"><a href="mailto:' . self::FROM . '" style="color:' . $teal . ';text-decoration:none;">' . self::FROM . '</a> · ' . self::PHONE . '</span></p>' : '')
            . ($custom ? '' : '<p class="pslink" style="margin:18px 0 0;padding-top:14px;border-top:1px solid #EEF2F6;font:400 13.5px/1.55 ' . $font . ';color:' . $muted . ';"><b style="color:' . $ink . ';">P.S.</b> Not the right person? Forward this to whoever looks after your centre’s administration, or reply and let me know who to speak with.</p>')
            . '</td></tr>'
            . '</table>'
            // CASL footer
            . '<table role="presentation" class="fine" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;"><tr><td class="foot" align="center" style="padding:18px 24px 6px;font:400 12px/1.6 ' . $font . ';color:#7A8699;">'
            . self::e($consent[1]) . '<br>' . self::e(self::ADDRESS) . '<br>'
            . '<a href="' . self::e($unsub) . '" style="color:#7A8699;text-decoration:underline;">Unsubscribe</a> from KiddieTrac sales emails · <a href="' . self::e($link('site')) . '" style="color:#7A8699;text-decoration:underline;">kiddietrac.com</a>'
            . '<div style="margin:10px 0 0;">' . SocialLinks::block(SocialLinks::row('#7A8699', 'font-family:Arial,Helvetica,sans-serif;', 'Follow us', '#7A8699')) . '</div>'
            . '</td></tr></table>' . $pixel
            . '</td></tr></table></body></html>';
    }

    /**
     * "YOUR OWN EMAIL" ON ITS CANVAS (2026-11-05, Anthony: "have it where it doesn't use the top header and footer as
     * optional ... create my own campaign from scratch ... totally customizable"). CampaignRich::canvas says what is
     * drawn: the KiddieTrac header / none / your own, the signature or not, the KiddieTrac footer / your own / the
     * shortest, and the colours, font, width, corners and spacing. Whatever the footer, the CASL line stays: who sent
     * it, the mailing address and the unsubscribe link (tracked per person, like every layout).
     */
    private static function renderCanvas(object $c, ?object $r, ?int $senderId): string
    {
        $cv = CampaignRich::canvas($c->canvas ?? null);
        $tok = $r ? $r->token : null;
        $link = fn (string $k) => $tok ? self::API . '/c/l/' . $tok . '/' . $k : self::linkTarget($k, $c);
        $unsub = $tok ? self::API . '/c/u/' . $tok : self::SITE . '/contact';
        $first = self::firstName($r ? $r->name : 'Alex Sample');
        $consent = self::CONSENT[$r->consent_basis ?? $c->consent_basis] ?? self::CONSENT['implied_published'];
        $font = CampaignRich::FONTS[$cv['font']][1];
        $ink = $cv['text']; $head = $cv['heading']; $acc = $cv['link'];
        $W = (int) $cv['width'];
        $pad = ['normal' => [34, 36], 'tight' => [20, 20]][$cv['pad']] ?? [34, 36];
        $rad = $cv['corners'] ? 20 : 0;
        $st = self::richStyles($font, $ink, $head, $acc);
        $src = CampaignRich::linkSource($c);
        $rich = fn (?string $h) => trim((string) $h) === '' ? '' : (CampaignRich::isRich($h) ? CampaignRich::html((string) $h, $st, $tok, $first, $src)
            : implode('', array_map(fn ($x) => '<p style="' . $st['p'] . '">' . nl2br(self::e($x)) . '</p>', array_filter(array_map('trim', preg_split("/\n\s*\n/", (string) $h))))));
        $cell = fn (string $inner, int $top, int $bottom) => $inner === '' ? '' : '<tr><td style="padding:' . $top . 'px ' . $pad[1] . 'px ' . $bottom . 'px;">' . $inner . '</td></tr>';
        // The small print under the email reads on the page colour: light grey on a dark page, slate on a light one.
        $hx = ltrim($cv['page_bg'], '#');
        $lum = (0.299 * hexdec(substr($hx, 0, 2)) + 0.587 * hexdec(substr($hx, 2, 2)) + 0.114 * hexdec(substr($hx, 4, 2))) / 255;
        $fine = $lum < 0.5 ? '#C3CCDA' : '#6B778A';

        $header = match ($cv['header']) {
            'kt' => '<tr><td bgcolor="#081C41" style="background:#081C41;padding:24px 32px 22px;" align="center"><img src="https://api.kiddietrac.com/email/wordmark-tagline-navy.png" width="200" alt="KiddieTrac" style="display:block;border:0;width:200px;max-width:60%;height:auto;"></td></tr>'
                . '<tr><td height="5" style="height:5px;line-height:5px;font-size:0;background:#0E8F99;background-image:linear-gradient(90deg,#1DB9C3,#6DB33F);">&nbsp;</td></tr>',
            // Only pictures (a banner): edge to edge. Words in it: the body's spacing.
            'own' => trim(MailHtml::text((string) $cv['header_html'])) === ''
                ? ($rich($cv['header_html']) === '' ? '' : '<tr><td style="padding:0;font-size:0;line-height:0;">' . preg_replace('#<p\b[^>]*>#i', '<p style="margin:0;">', $rich($cv['header_html'])) . '</td></tr>')
                : $cell($rich($cv['header_html']), $pad[0], 0),
            default => '',
        };
        $headline = trim((string) $c->headline) !== '' ? '<h1 class="hd" style="margin:0 0 18px;font:800 27px/1.22 ' . $font . ';color:' . $head . ';">' . self::e($c->headline) . '</h1>' : '';
        $body = $cell($headline . $rich((string) $c->intro), $pad[0], $cv['signature'] || $cv['footer'] === 'own' ? 4 : max($pad[0], $pad[1] ? 26 : 0));
        $sign = $cv['signature']
            ? $cell('<p style="margin:14px 0 0;font:400 15px/1.55 ' . $font . ';color:' . $ink . ';">Talk soon,<br><b>' . self::e(self::senderName($senderId)) . '</b><br>'
                . '<span style="font-size:13.5px;"><a class="acc" href="mailto:' . self::FROM . '" style="color:' . $acc . ';text-decoration:none;">' . self::FROM . '</a> · ' . self::PHONE . '</span></p>', 8, $cv['footer'] === 'own' ? 8 : 30)
            : '';
        $ownFoot = $cv['footer'] === 'own' && trim((string) $cv['footer_html']) !== ''
            ? '<tr><td style="padding:16px ' . $pad[1] . 'px ' . max(24, $pad[0]) . 'px;border-top:1px solid #E2E8F0;" class="ownfoot">' . $rich($cv['footer_html']) . '</td></tr>' : '';
        $legal = '<a href="' . self::e($unsub) . '" style="color:' . $fine . ';text-decoration:underline;">Unsubscribe</a>';
        $foot = $cv['footer'] === 'kt'
            ? self::e($consent[1]) . '<br>' . self::e(self::ADDRESS) . '<br>' . $legal . ' from KiddieTrac sales emails · <a href="' . self::e($link('site')) . '" style="color:' . $fine . ';text-decoration:underline;">kiddietrac.com</a>'
                . '<div style="margin:10px 0 0;">' . SocialLinks::block(SocialLinks::row($fine, 'font-family:Arial,Helvetica,sans-serif;', 'Follow us', $fine)) . '</div>'
            : self::e(self::ADDRESS) . ' · ' . $legal;   // the CASL line every footer keeps
        $pixel = $tok ? '<img src="' . self::API . '/c/o/' . $tok . '.gif" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;">' : '';
        $pre = trim((string) $c->preheader);

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark"><title>' . self::e($c->subject) . '</title>'
            . '<style>@media (prefers-color-scheme:dark){body.bg,table.bg,td.bg{background:#0B1220 !important}table.sheet{background:#121B2E !important;box-shadow:none !important}.sheet p,.sheet td,.sheet div,.sheet span,.sheet li{color:#CBD5E1 !important}.sheet h1,.sheet b,.sheet .hd{color:#F1F5F9 !important}.sheet a.acc{color:#5FD3DB !important}.sheet .ownfoot{border-color:#26344F !important}.foot,.foot a{color:#8A97AB !important}}'
            . '@media (max-width:620px){table.sheet,table.fine{width:100% !important}td.bg{padding:12px 8px !important}}</style></head>'
            . '<body class="bg" style="margin:0;padding:0;background:' . $cv['page_bg'] . ';-webkit-text-size-adjust:100%;">'
            . ($pre !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . self::e($pre) . str_repeat('&#8199;&#847; ', 30) . '</div>' : '')
            . '<table role="presentation" class="bg" width="100%" cellpadding="0" cellspacing="0" bgcolor="' . $cv['page_bg'] . '"><tr><td class="bg" align="center" style="padding:28px 12px;">'
            . '<table role="presentation" class="sheet" width="' . $W . '" cellpadding="0" cellspacing="0" bgcolor="' . $cv['sheet_bg'] . '" style="width:' . $W . 'px;max-width:100%;background:' . $cv['sheet_bg'] . ';border-radius:' . $rad . 'px;overflow:hidden;' . ($rad ? 'box-shadow:0 10px 40px rgba(11,31,77,.10);' : '') . '">'
            . $header . $body . $sign . $ownFoot
            . '</table>'
            . '<table role="presentation" class="fine" width="' . $W . '" cellpadding="0" cellspacing="0" style="width:' . $W . 'px;max-width:100%;"><tr><td class="foot" align="center" style="padding:18px 24px 6px;font:400 12px/1.6 ' . $font . ';color:' . $fine . ';">'
            . $foot . '</td></tr></table>' . $pixel
            . '</td></tr></table></body></html>';
    }

    /** The standard layout's type, for a rich body (CampaignRich::html). */
    private static function richStyles(string $font, string $ink, string $navy, string $teal): array
    {
        return [
            'p' => 'margin:0 0 14px;font:400 15.5px/1.62 ' . $font . ';color:' . $ink . ';',
            'li' => 'margin:0 0 6px;font:400 15.5px/1.62 ' . $font . ';color:' . $ink . ';',
            'h1' => 'margin:6px 0 12px;font:800 24px/1.25 ' . $font . ';color:' . $navy . ';',
            'h2' => 'margin:6px 0 10px;font:800 19px/1.3 ' . $font . ';color:' . $navy . ';',
            'h3' => 'margin:4px 0 8px;font:800 16px/1.35 ' . $font . ';color:' . $navy . ';',
            'a' => 'color:' . $teal . ';font-weight:700;',
        ];
    }

    /** Send one recipient (or a test to $testTo, untracked). Returns [ok, error]. */
    /* SEND FROM ANY PORTAL MAILBOX (2026-11-09, Anthony: "with sales campaign add option to send from any mailbox
       that is setup on the portal (platform emails)"). A campaign may name one of KiddieTrac's own mailboxes connected
       in Sales > Email (sales_campaigns.from_account_id); it then goes out from that mailbox, replies come back to it,
       and if Microsoft will not send from it that person's email fails with the reason (never a silent other sender).
       Unset = as before: sales@ for written emails, the no-reply sender with Reply-To info@ for subscriber designs. */
    public static function mailboxes(): array
    {
        try {
            return DB::table('email_accounts')->whereNull('agency_id')->whereNull('deleted_at')->where('provider', 'microsoft')
                ->where('status', 'connected')->whereNotNull('email_address')->orderBy('id')->get(['id', 'email_address', 'display_name'])
                ->map(fn ($a) => ['id' => (int) $a->id, 'email' => mb_strtolower(trim((string) $a->email_address)),
                    'name' => trim((string) $a->display_name) !== '' ? trim((string) $a->display_name) : 'KiddieTrac'])->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** The mailbox a campaign goes out from: ['id','email','name'], or null for the usual sender. */
    public static function sender(object $c): ?array
    {
        $id = (int) ($c->from_account_id ?? 0);
        if (! $id) return null;
        foreach (self::mailboxes() as $m) if ($m['id'] === $id) return $m;
        return ['id' => $id, 'email' => null, 'name' => null];   // chosen, but no longer connected: refuse, never substitute
    }

    /** What a recipient row says when the mail gate cancelled the message (2026-11-11): it was counted as sent before. */
    public const HELD = 'Held back by the mail gate (see Email log).';

    public static function sendOne(object $c, ?object $r, ?string $testTo = null, ?int $senderId = null): array
    {
        $box = self::sender($c);
        if ($box && ! $box['email']) return [false, 'The mailbox this campaign sends from is no longer connected. Pick another sender.'];
        $to = $testTo ?: ($r ? $r->email : null);
        if (! $to || ! filter_var($to, FILTER_VALIDATE_EMAIL)) return [false, 'No valid address'];
        $html = self::render($c, $testTo ? null : $r, $senderId ?: ($c->sent_by ?: $c->created_by));
        $unsubUrl = $r && ! $testTo ? self::API . '/c/u/' . $r->token : null;
        if (! empty($c->template)) {
            // Subscriber emails go as KiddieTrac platform mail from the no-reply sender (the copy says "Let's chat" / info@,
            // never "reply"), like SubscriberCampaign::sendOne, and each one is recorded so no path sends it twice.
            try {
                $went = Mail::html($html, function ($m) use ($c, $r, $to, $testTo, $unsubUrl, $box) {
                    MailScope::platform($m);
                    $m->to($to, $testTo ? null : ($r->name ?: null))->subject(($testTo ? '[TEST] ' : '') . $c->subject);
                    if ($box) $m->from($box['email'], $box['name'])->replyTo($box['email'], $box['name']); else $m->replyTo('info@kiddietrac.com', 'KiddieTrac');
                    $h = $m->getHeaders();
                    if ($box) { $h->addTextHeader('X-KT-Send-As', $box['email']); $h->addTextHeader('X-KT-Send-As-Strict', '1'); }
                    $h->addTextHeader('X-KT-Campaign', 'sales-' . (int) $c->id);
                    if ($unsubUrl) {
                        $h->addTextHeader('List-Unsubscribe', '<' . $unsubUrl . '>, <mailto:info@kiddietrac.com?subject=unsubscribe>');
                        $h->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
                    }
                });
                if ($went === null) return [false, self::HELD];
                if ($r && ! $testTo && \Illuminate\Support\Facades\Schema::hasTable('subscriber_campaign_sends')) {
                    DB::table('subscriber_campaign_sends')->insertOrIgnore(['campaign' => (string) $c->template, 'email' => mb_strtolower(trim((string) $r->email)), 'sent_at' => now()]);
                }
                return [true, null];
            } catch (\Throwable $e) {
                Log::warning('subscriber campaign send failed', ['campaign' => $c->id, 'recipient' => $r->id ?? null, 'error' => $e->getMessage()]);
                return [false, mb_substr($e->getMessage(), 0, 290)];
            }
        }
        try {
            $went = Mail::html($html, function ($m) use ($c, $r, $to, $testTo, $unsubUrl, $box) {
                MailScope::platform($m);   // KiddieTrac's own sales mail, not tenant mail
                $m->to($to, $testTo ? null : ($r->name ?: null))->subject(($testTo ? '[TEST] ' : '') . $c->subject);
                if ($box) $m->from($box['email'], $box['name'])->replyTo($box['email'], $box['name']); else $m->replyTo(self::FROM, 'KiddieTrac Sales');
                $h = $m->getHeaders();
                $h->addTextHeader('X-KT-Send-As', $box ? $box['email'] : self::FROM);
                if ($box) $h->addTextHeader('X-KT-Send-As-Strict', '1');
                $h->addTextHeader('X-KT-Campaign', 'sales-' . (int) $c->id);
                if ($unsubUrl) {   // one-click unsubscribe (RFC 8058) for Gmail / Outlook's own button
                    $h->addTextHeader('List-Unsubscribe', '<' . $unsubUrl . '>, <mailto:' . self::FROM . '?subject=unsubscribe>');
                    $h->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
                }
            });
            if ($went === null) return [false, self::HELD];
            return [true, null];
        } catch (\Throwable $e) {
            Log::warning('sales campaign send failed', ['campaign' => $c->id, 'recipient' => $r->id ?? null, 'error' => $e->getMessage()]);
            return [false, mb_substr($e->getMessage(), 0, 290)];
        }
    }

    /** Recount a campaign's totals from its recipients. */
    public static function recount(int $campaignId): void
    {
        $q = fn () => DB::table('sales_campaign_recipients')->where('campaign_id', $campaignId);
        DB::table('sales_campaigns')->where('id', $campaignId)->update([
            'recipient_count' => $q()->count(),
            'sent_count' => $q()->where('status', 'sent')->count(),
            'open_count' => $q()->whereNotNull('opened_at')->count(),
            'click_count' => $q()->whereNotNull('clicked_at')->count(),
            'response_count' => $q()->whereNotNull('response')->where('response', '!=', 'unsubscribed')->count(),
            'unsub_count' => $q()->where('response', 'unsubscribed')->count(),
            'updated_at' => now(),
        ]);
    }

    public static function suppressed(string $email): bool
    {
        return DB::table('sales_suppressions')->where('email', mb_strtolower(trim($email)))->exists();
    }

    /** A mail scanner opening a link seconds after the send is not the person (same rule as proposal tracking). */
    public static function isScan(object $r, Request $req): bool
    {
        if ($r->sent_at && now()->diffInSeconds(Carbon::parse($r->sent_at), true) < 20) return true;
        $ua = strtolower((string) $req->userAgent());
        return $ua === '' || (bool) preg_match('/bot|crawler|spider|preview|scanner|safelinks|proofpoint|mimecast|barracuda|urldefense/', $ua);
    }

    public static function opened(object $r, Request $req): void
    {
        if (self::isScan($r, $req)) return;
        DB::table('sales_campaign_recipients')->where('id', $r->id)->update([
            'opened_at' => $r->opened_at ?: now(), 'open_count' => DB::raw('open_count + 1'), 'updated_at' => now(),
        ]);
        if (! $r->opened_at) self::recount((int) $r->campaign_id);
        ColdReach::fromCampaign($r, 'opened', $req);   // a cold-reach send: the prospect shows the open too
    }

    public static function clicked(object $r, string $key, Request $req): ?string
    {
        ColdReach::fromCampaign($r, 'clicked');
        $c = DB::table('sales_campaigns')->where('id', $r->campaign_id)->first();
        $url = $c ? self::linkTarget($key, $c) : null;
        if (! $url) return null;
        if (! self::isScan($r, $req)) {
            DB::table('sales_campaign_recipients')->where('id', $r->id)->update([
                'clicked_at' => $r->clicked_at ?: now(), 'click_count' => DB::raw('click_count + 1'), 'last_link' => $key,
                'opened_at' => $r->opened_at ?: now(), 'updated_at' => now(),
            ]);
            self::recount((int) $r->campaign_id);
        }
        return $url;
    }

    /**
     * Someone responded: interested | booked | replied | unsubscribed. Leads + a follow-up task + the team told
     * (except for an unsubscribe, which only suppresses). Idempotent: a second response of the same kind does nothing.
     */
    public static function respond(object $r, string $kind, ?int $byUserId = null, ?string $detail = null): void
    {
        if (! in_array($kind, ['interested', 'booked', 'replied', 'unsubscribed'], true)) return;
        ColdReach::fromCampaign($r, $kind);   // a cold-reach send: the prospect follows (2026-10-30)
        $fresh = DB::table('sales_campaign_recipients')->where('id', $r->id)->first();
        if (! $fresh) return;
        $c = DB::table('sales_campaigns')->where('id', $fresh->campaign_id)->first();
        if ($kind === 'unsubscribed') {
            DB::table('sales_suppressions')->updateOrInsert(['email' => mb_strtolower(trim($fresh->email))],
                ['reason' => 'unsubscribed', 'campaign_id' => $fresh->campaign_id, 'added_by' => $byUserId, 'updated_at' => now(), 'created_at' => now()]);
            DB::table('sales_campaign_recipients')->where('id', $fresh->id)->update(['response' => 'unsubscribed', 'responded_at' => now(), 'updated_at' => now()]);
            DB::table('sales_campaign_recipients')->where('email', $fresh->email)->where('status', 'queued')->update(['status' => 'skipped', 'skip_reason' => 'unsubscribed', 'updated_at' => now()]);
            // Off the KiddieTrac subscriber list as well, when it was a subscriber email (2026-10-27).
            if ($c && ! empty($c->template) && \Illuminate\Support\Facades\Schema::hasTable('site_subscribers')) {
                DB::table('site_subscribers')->where('email', mb_strtolower(trim($fresh->email)))->whereNull('unsubscribed_at')
                    ->update(['unsubscribed_at' => now(), 'unsubscribed_by' => 'campaign', 'unsubscribe_note' => mb_substr('Unsubscribed from "' . $c->name . '"', 0, 190), 'updated_at' => now()]);
            }
            self::recount((int) $fresh->campaign_id);
            return;
        }
        // A stronger signal replaces a weaker one (interested < replied < booked); never downgrade.
        $rank = ['interested' => 1, 'replied' => 2, 'booked' => 3];
        if ($fresh->response && $fresh->response !== 'unsubscribed' && ($rank[$fresh->response] ?? 0) >= $rank[$kind]) return;
        $leadId = self::ensureLead($fresh, $c, $byUserId);
        $label = ['interested' => 'asked for more information', 'replied' => 'replied to the email', 'booked' => 'booked a demo on the website'][$kind];
        $actId = null;
        try {
            $due = Carbon::now('America/Toronto')->addWeekday()->setTime(10, 0);
            $actId = DB::table('sales_activities')->insertGetId([
                'lead_id' => $leadId, 'user_id' => $byUserId ?: ($c->sent_by ?: $c->created_by), 'type' => 'followup',
                'body' => '📣 ' . ($fresh->name ?: $fresh->email) . ' ' . $label . ' (campaign “' . $c->name . '”). Follow up' . ($kind === 'booked' ? ' before the demo.' : '.')
                    . ($detail ? "\n\n" . mb_substr($detail, 0, 900) : ''),
                'due_date' => $due->toDateString(), 'done' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('sales_leads')->where('id', $leadId)->update(['last_activity_at' => now(), 'follow_up_date' => $due->toDateString(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('campaign follow-up task failed', ['recipient' => $fresh->id, 'error' => $e->getMessage()]);
        }
        DB::table('sales_campaign_recipients')->where('id', $fresh->id)->update([
            'response' => $kind, 'responded_at' => now(), 'lead_id' => $leadId, 'followup_activity_id' => $actId ?: $fresh->followup_activity_id, 'updated_at' => now(),
        ]);
        self::recount((int) $fresh->campaign_id);
        self::notify($fresh, $c, $kind, $leadId, $detail);
    }

    /** The lead for this recipient: theirs, an existing lead with the same email, or a new one. Returns its id. */
    public static function ensureLead(object $r, object $c, ?int $byUserId = null): int
    {
        if ($r->lead_id && DB::table('sales_leads')->where('id', $r->lead_id)->whereNull('deleted_at')->exists()) return (int) $r->lead_id;
        $existing = DB::table('sales_leads')->whereNull('deleted_at')->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($r->email))])->orderByDesc('id')->value('id');
        if ($existing) {
            DB::table('sales_campaign_recipients')->where('id', $r->id)->update(['lead_id' => $existing, 'updated_at' => now()]);
            return (int) $existing;
        }
        $id = DB::table('sales_leads')->insertGetId([
            'name' => $r->name ?: $r->email, 'company' => $r->company, 'email' => $r->email, 'source' => 'Sales campaign', 'stage' => 'contacted', 'status' => 'open',
            'owner_id' => $byUserId ?: ($c->sent_by ?: $c->created_by), 'notes' => 'From the sales campaign “' . $c->name . '”.',
            'last_activity_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales_campaign_recipients')->where('id', $r->id)->update(['lead_id' => $id, 'updated_at' => now()]);
        return (int) $id;
    }

    /** Tell the sales team (superadmins To, sales reps Cc - the new-lead list). Never throws. */
    private static function notify(object $r, object $c, string $kind, int $leadId, ?string $detail): void
    {
        try {
            $by = fn (string $role) => DB::table('users')->join('role_assignments as ra', 'ra.user_id', '=', 'users.id')
                ->where('ra.role', $role)->where('ra.active', 1)->whereNull('users.deleted_at')->whereNotNull('users.email')->distinct()->pluck('users.email')->all();
            $to = $by('platform_admin');
            $cc = array_values(array_diff($by('sales_rep'), $to));
            if (! $to) { $to = $cc; $cc = []; }
            if (! $to) return;
            $what = ['interested' => 'asked for more information', 'replied' => 'replied to your campaign', 'booked' => 'booked a demo'][$kind];
            $icon = ['interested' => '🙋', 'replied' => '💬', 'booked' => '📅'][$kind];
            $html = SalesMail::render([
                'preheader' => ($r->name ?: $r->email) . ' ' . $what,
                'eyebrow' => 'Campaign response', 'icon' => $icon,
                'title' => ($r->company ?: ($r->name ?: $r->email)) . ' ' . $what,
                'subtitle' => 'Campaign: ' . $c->name,
                'intro' => 'They are in Leads now, with a follow-up task for the next working day.',
                'sections' => [['heading' => 'Contact', 'rows' => ['Name' => $r->name, 'Company' => $r->company, 'Email' => $r->email]]],
                'message' => $detail, 'message_label' => 'Their reply',
                'button' => ['label' => 'Open lead', 'url' => 'https://app.kiddietrac.com/dashboard.html#sales-lead?id=' . $leadId],
            ]);
            Mail::html($html, function ($m) use ($to, $cc, $r, $what, $icon) {
                MailScope::platform($m);
                $m->to($to)->subject($icon . ' ' . ($r->company ?: ($r->name ?: $r->email)) . ' ' . $what);
                if ($cc) $m->cc($cc);
                $m->replyTo($r->email, $r->name ?: null);
            });
        } catch (\Throwable $e) {
            Log::warning('campaign response notify failed', ['recipient' => $r->id, 'error' => $e->getMessage()]);
        }
    }

    /** A website booking from someone a campaign reached (same email, after the send) is a response. */
    public static function attributeBookings(): int
    {
        // Two steps, not a JOIN: the two tables' email columns use different collations.
        $n = 0;
        $open = DB::table('sales_campaign_recipients')->where('status', 'sent')->where('sent_at', '>=', now()->subDays(90))
            ->where(fn ($q) => $q->whereNull('response')->orWhereIn('response', ['interested', 'replied']))->get();
        if ($open->isEmpty()) return 0;
        $emails = $open->pluck('email')->map(fn ($e) => mb_strtolower(trim($e)))->unique()->values()->all();
        $booked = [];
        foreach (array_chunk($emails, 500) as $chunk) {
            foreach (DB::table('marketing_bookings')->whereIn(DB::raw('LOWER(TRIM(email))'), $chunk)->get(['email', 'created_at']) as $b) {
                $k = mb_strtolower(trim($b->email));
                if (! isset($booked[$k]) || $b->created_at > $booked[$k]) $booked[$k] = $b->created_at;
            }
        }
        foreach ($open as $r) {
            $k = mb_strtolower(trim($r->email));
            if (isset($booked[$k]) && $booked[$k] >= $r->sent_at && $n < 50) { self::respond($r, 'booked'); $n++; }
        }
        return $n;
    }

    /**
     * Replies, seen in the connected sales mailbox: a message in its inbox from a campaign recipient after their email
     * went out. Needs Sales -> Email connected with Microsoft; without it, "Mark replied" on the campaign does the same.
     *
     * A REPLY TO THE CAMPAIGN, NOT ANY EMAIL (2026-11-05). Every email from a recipient used to count, so people who
     * also write to us about other things (iLearn's own addresses are subscribers: "Bank Account", "Fw: Screenshot")
     * became "Sales campaign" leads again after each delete, and one email counted for every campaign they were on.
     * Now the subject must be a reply prefix (Re:, RE:, AW:, SV:, Réf: ...) and then that campaign's subject;
     * forwards and automatic replies (out of office) never count.
     */
    public static function replySubject(?string $subject): ?string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $subject));
        if (preg_match('/^(automatic reply|auto(matische)? ?(reply|antwort)|out of (the )?office|r[ée]ponse automatique|respuesta autom[áa]tica|undeliverable|delivery status)/iu', $s)) return null;
        $replied = false;
        while (preg_match('/^(re|aw|sv|antw|r[ée]f|r[ée]p|ynt|fw|fwd|tr|wg|rv)\s*(\[\d+\])?\s*:\s*/iu', $s, $m)) {
            if (preg_match('/^(fw|fwd|tr|wg|rv)/iu', $m[1])) return null;   // a forward is not a reply
            $replied = true;
            $s = mb_substr($s, mb_strlen($m[0]));
        }
        return $replied ? mb_strtolower(trim($s)) : null;
    }

    public static function detectReplies(): int
    {
        $a = SalesMailbox::account();
        if (! $a || ! MicrosoftMailbox::uses($a) || $a->status !== 'connected') return 0;
        $since = DB::table('sales_campaign_recipients')->where('status', 'sent')->where('sent_at', '>=', now()->subDays(60))
            ->where(fn ($q) => $q->whereNull('response')->orWhere('response', 'interested'))->min('sent_at');
        if (! $since) return 0;
        $n = 0;
        try {
            $res = MicrosoftMailbox::call($a, 'GET', '/mailFolders/inbox/messages', [
                '$filter' => 'receivedDateTime ge ' . Carbon::parse($since)->utc()->format('Y-m-d\TH:i:s\Z'),
                '$select' => 'from,subject,receivedDateTime,bodyPreview', '$orderby' => 'receivedDateTime desc', '$top' => 100,
            ]);
            $msgs = is_array($res) ? ($res['value'] ?? []) : (method_exists($res, 'json') ? ($res->json('value') ?: []) : []);
            foreach ($msgs as $msg) {
                $from = mb_strtolower((string) ($msg['from']['emailAddress']['address'] ?? ''));
                if ($from === '') continue;
                $subj = self::replySubject($msg['subject'] ?? '');
                if ($subj === null || $subj === '') continue;
                // The newest campaign this person got whose subject this answers.
                $r = DB::table('sales_campaign_recipients as r')->join('sales_campaigns as c', 'c.id', '=', 'r.campaign_id')
                    ->whereRaw('LOWER(r.email) = ?', [$from])->where('r.status', 'sent')
                    ->where('r.sent_at', '<=', Carbon::parse($msg['receivedDateTime'])->utc())
                    ->where(fn ($q) => $q->whereNull('r.response')->orWhere('r.response', 'interested'))
                    ->orderByDesc('r.sent_at')->get(['r.*', 'c.subject as campaign_subject'])
                    ->first(fn ($x) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $x->campaign_subject))) === $subj);
                if (! $r) continue;
                unset($r->campaign_subject);
                self::respond($r, 'replied', null, trim((string) ($msg['subject'] ?? '')) . "\n" . trim((string) ($msg['bodyPreview'] ?? '')));
                $n++;
            }
        } catch (\Throwable $e) {
            Log::info('campaign reply check skipped: ' . $e->getMessage());
        }
        return $n;
    }

    /** A plain branded page for the public links (interested / unsubscribe). */
    public static function page(string $title, string $bodyHtml): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::e($title) . ' · KiddieTrac</title>'
            . '<link rel="icon" href="https://www.kiddietrac.com/favicon.png"><style>body{margin:0;background:#EEF2F7;font-family:Segoe UI,Arial,sans-serif;color:#1E293B}'
            . '.w{max-width:560px;margin:40px auto;padding:0 16px}.c{background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 10px 40px rgba(11,31,77,.10)}'
            . '.b{background:#081C41;padding:22px;text-align:center}.s{height:5px;background:linear-gradient(90deg,#1DB9C3,#6DB33F)}.i{padding:30px 32px 32px;font-size:15.5px;line-height:1.6}'
            . 'h1{font-size:24px;color:#0B1F4D;margin:0 0 12px}.btn{display:inline-block;background:linear-gradient(135deg,#0E8F99,#1F6080);color:#fff;text-decoration:none;font-weight:800;padding:14px 26px;border-radius:12px;border:0;font-size:15.5px;cursor:pointer;font-family:inherit}'
            . '.alt{display:inline-block;margin-left:10px;color:#0E8F99;font-weight:700;text-decoration:none}.f{text-align:center;color:#7A8699;font-size:12px;margin:16px 0}</style></head><body><div class="w"><div class="c">'
            . '<div class="b"><img src="https://api.kiddietrac.com/email/wordmark-tagline-navy.png" width="190" alt="KiddieTrac" style="max-width:60%;height:auto"></div><div class="s"></div>'
            . '<div class="i">' . $bodyHtml . '</div></div><div class="f">' . self::e(self::ADDRESS) . '</div></div></body></html>';
    }
}
