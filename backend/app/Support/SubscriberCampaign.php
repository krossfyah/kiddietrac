<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * EMAIL TO THE KIDDIETRAC SUBSCRIBER LIST (2026-10-27, Anthony: "make a new campaign addressed to all
 * subscribers based on the One-Tap video and feature ... ask your educator/centre/childcare + agency today
 * to learn more ... send me the test email before we send this out").
 *
 * site_subscribers holds parents, educators, staff, directors and leads, so one email speaks to all of them,
 * with a part for each. Sent from KiddieTrac (platform mail), each copy with that person's own signed
 * unsubscribe link (LeadWelcome::unsubscribeUrl) and the one-click List-Unsubscribe headers. Unsubscribed
 * and suppressed addresses are skipped, and subscriber_campaign_sends makes a second run send nothing twice.
 * kiddietrac:subscriber-campaign onetap --test=<email> sends one [TEST] copy; --send is the real thing.
 */
final class SubscriberCampaign
{
    private const SITE = 'https://www.kiddietrac.com';

    /** The templates a Sales campaign can use: key => label (2026-10-27). */
    public const TEMPLATES = ['onetap' => 'One-Tap', 'halloween' => 'Halloween', 'playspark' => 'Play Spark'];

    /** The inbox preview line for a template that sets one (2026-11-24). */
    public const PREHEADER = ['playspark' => 'Rainy day, restless kids, nothing planned? One tap gives you an idea for every age in the room.'];

    public static function subject(string $key): string
    {
        return ['onetap' => 'Meet One-Tap: check-in and pick-up in one tap', 'halloween' => 'Happy Halloween from KiddieTrac 🎃', 'playspark' => 'One tap. A play idea for every age in the room.'][$key] ?? '';
    }

    /* DESIGN v5 (2026-10-27): white header + teal bar, the product hero, a compact film row, icon steps. v2 (2026-10-27, Anthony: "too bold - make it professional and more pleasing"). A white page, a slim
       navy header, a quiet headline, teal only as an accent, hairline-bordered sections, one emphasis per block. */
    /** $unsubUrl: the link the footer uses; a Sales campaign passes the recipient's tracked one (/c/u/{token}). */
    public static function render(string $key, string $email, string $name = '', ?string $unsubUrl = null): string
    {
        if ($key === 'halloween') return self::renderHalloween($email, $name, $unsubUrl);
        if ($key === 'playspark') return self::renderPlaySpark($email, $name, $unsubUrl);
        $first = trim((string) strtok(trim($name), ' '));
        $unsub = $unsubUrl ?: LeadWelcome::unsubscribeUrl($email);
        $S = self::SITE;
        $film = $S . '/watch/one-tap/';
        $font = '-apple-system,Segoe UI,Helvetica,Arial,sans-serif';
        $navy = '#0F2A4A'; $teal = '#0E7C86'; $ink = '#1F2937'; $body = '#4B5563'; $muted = '#8A94A3'; $line = '#E6EAF0';
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $p = fn ($h, $x = '') => '<p style="margin:0 0 14px;font:400 15px/1.7 ' . $font . ';color:' . $body . ';' . $x . '">' . $h . '</p>';
        $label = fn ($t) => '<div style="font:600 11px/1 ' . $font . ';letter-spacing:1.4px;text-transform:uppercase;color:' . $teal . ';margin:0 0 10px;">' . $t . '</div>';
        $h2 = fn ($t) => '<div class="hd" style="font:600 20px/1.35 ' . $font . ';color:' . $navy . ';margin:0 0 10px;">' . $t . '</div>';
        $btn = fn ($l, $u) => '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td bgcolor="' . $teal . '" style="border-radius:8px;background:' . $teal . ';">'
            . '<a href="' . $e($u) . '" style="display:inline-block;padding:11px 20px;font:600 14px/1 ' . $font . ';color:#ffffff !important;text-decoration:none;border-radius:8px;">' . $l . '</a></td></tr></table>';
        $link = fn ($l, $u) => '<a href="' . $e($u) . '" style="font:600 14px/1.4 ' . $font . ';color:' . $teal . ' !important;text-decoration:none;">' . $l . ' &rarr;</a>';
        $hr = '<tr><td class="pad" style="padding:0 40px;"><div style="height:1px;line-height:1px;background:' . $line . ';">&nbsp;</div></td></tr>';
        $ask = fn ($subject, $bodyTxt) => 'mailto:?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($bodyTxt);
        $askParent = $ask('Could we use One-Tap at our centre?', "Hi,\n\nI just watched how KiddieTrac's One-Tap check-in works: parents scan the centre's daily code with their own phone, the educator sees who is at the door with their photo, and both parents and the room are told right away.\n\nCould we use One-Tap at our centre? Here's the 2-minute film: $film\n\nThank you!");
        $askEducator = $ask('One-Tap check-in for our room?', "Hi,\n\nKiddieTrac's One-Tap would take the sign-in sheet off our hands at drop-off and pick-up: parents scan today's code, we see who it is with their photo, and it's all time-stamped for us.\n\nCan we turn it on? 2-minute film: $film");

        $why = [
            ['The right adult, every time', 'The parent&rsquo;s photo, name and relationship appear the moment they scan, so the educator can confirm the hand-off at a glance.'],
            ['Faster drop-off and pick-up', 'No sign-in sheet, shared pen or tablet to pass around. The door keeps moving, even at the busiest times.'],
            ['Everyone stays informed', 'The other parent and the room&rsquo;s educators are notified instantly, and each check-in and check-out is time-stamped.'],
            ['Secure by design', 'The code changes every day and is signed. A photo of an old code, or another centre&rsquo;s, is refused.'],
        ];
        $whyRows = '';
        foreach (array_chunk($why, 2) as $row) {
            $whyRows .= '<tr>';
            foreach ($row as $i => $w) {
                $whyRows .= '<td class="col" width="50%" valign="top" style="padding:' . ($i ? '0 0 18px 12px' : '0 12px 18px 0') . ';">'
                    . '<div style="width:24px;height:2px;background:' . $teal . ';margin:0 0 10px;font-size:0;line-height:0;">&nbsp;</div>'
                    . '<div class="hd" style="font:600 15px/1.4 ' . $font . ';color:' . $navy . ';margin:0 0 4px;">' . $w[0] . '</div>'
                    . '<div style="font:400 14px/1.6 ' . $font . ';color:' . $body . ';">' . $w[1] . '</div></td>';
            }
            $whyRows .= '</tr>';
        }
        $steps = [
            ['Today&rsquo;s code at the door', 'Shown on a tablet or a printed One-Tap poster; it changes daily.'],
            ['Parents scan with their own app', 'Nothing to touch or share at the door.'],
            ['The educator confirms', '&ldquo;Scanned by&rdquo; shows the parent&rsquo;s photo and the children being dropped off or picked up.'],
            ['Recorded automatically', 'Attendance updates and families are notified.'],
        ];
        $stepRows = '';
        foreach (array_chunk($steps, 2, true) as $row) {
            $stepRows .= '<tr>';
            foreach ($row as $i => $st) {
                $stepRows .= '<td class="col" width="50%" valign="top" style="padding:' . ($i % 2 ? '0 0 18px 9px' : '0 9px 18px 0') . ';">'
                    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="card stepc" bgcolor="#FFFFFF" style="background:#ffffff;border:1px solid ' . $line . ';border-radius:12px;">'
                    . '<div style="padding:18px 18px 16px;"><img src="' . $S . '/email-icons/onetap/icon-step' . ($i + 1) . '-t.png" width="64" height="64" alt="" style="display:block;width:64px;height:64px;border:0;margin:0 0 12px;"><div style="font:600 12px/1 ' . $font . ';color:' . $teal . ';letter-spacing:1px;margin:0 0 6px;">STEP 0' . ($i + 1) . '</div>'
                    . '<div class="hd" style="font:600 15px/1.35 ' . $font . ';color:' . $navy . ';margin:0 0 4px;">' . $st[0] . '</div>'
                    . '<div style="font:400 13.5px/1.55 ' . $font . ';color:' . $body . ';">' . $st[1] . '</div></div></td></tr></table></td>';
            }
            $stepRows .= '</tr>';
        }
        $who = function ($title, $text, $cta) use ($font, $navy, $body, $line) {
            return '<tr><td style="padding:0 0 12px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="card" style="border:1px solid ' . $line . ';border-radius:10px;padding:16px 18px;">'
                . '<div class="hd" style="font:600 15px/1.4 ' . $font . ';color:' . $navy . ';margin:0 0 4px;">' . $title . '</div>'
                . '<div style="font:400 14px/1.6 ' . $font . ';color:' . $body . ';margin:0 0 10px;">' . $text . '</div>' . $cta . '</td></tr></table></td></tr>';
        };

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark"><title>' . $e(self::subject($key)) . '</title>'
            . '<style>@media (max-width:620px){.wrap{width:100% !important}.pad{padding-left:22px !important;padding-right:22px !important}.col{display:block !important;width:100% !important;padding:0 0 16px !important}}'
            . '@media (prefers-color-scheme:dark){.body-bg{background:#0E1525 !important}.sheet{background:#141D2F !important}.card{border-color:#2A3650 !important}'
            . '.sheet div,.sheet p,.sheet td{color:#C9D3E0 !important}.sheet .hd{color:#F1F5F9 !important}.keep,.keep *{color:#FFFFFF !important}.sheet td.top{background:#141D2F !important}.sheet td.stepc{background:#18233A !important}.logo-l{display:none !important}.logo-d{display:block !important;max-height:none !important}.sheet td.foot{background:#0F1828 !important;border-color:#2A3650 !important;color:#8A97AB !important}.sheet td.foot a{color:#9FB0C6 !important}.sheet td.pill{background:#12363C !important;color:#7FD8DF !important}.sheet td.chat{background:#16283A !important;border-color:#24425A !important}}</style></head>'
            . '<body class="body-bg" style="margin:0;padding:0;background:#F3F5F8;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">One-Tap makes drop-off and pick-up faster and safer. Watch how it works in two minutes.&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="body-bg" bgcolor="#F3F5F8" style="background:#F3F5F8;"><tr><td align="center" style="padding:28px 12px;">'
            . '<table role="presentation" class="wrap sheet" width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="#FFFFFF" style="width:600px;max-width:600px;background:#ffffff;border:1px solid ' . $line . ';border-radius:12px;overflow:hidden;">'
            // header (v5): a teal accent bar, the navy wordmark on white (the white one in dark mode), "Product update"
            . '<tr><td style="height:4px;line-height:4px;font-size:0;background:#0E7C86;background-image:linear-gradient(90deg,#0E7C86,#14B8C4);">&nbsp;</td></tr>'
            . '<tr><td class="pad top" bgcolor="#FFFFFF" style="background:#ffffff;padding:20px 40px 18px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td><a href="' . $S . '/"><img class="logo-l" src="' . $S . '/email-icons/brand/kiddietrac-wordmark-dark.png" width="116" alt="KiddieTrac" style="display:block;border:0;width:116px;height:auto;">'
            . '<!--[if !mso]><!--><img class="logo-d" src="' . $S . '/email-icons/brand/kiddietrac-wordmark.png" width="132" alt="KiddieTrac" style="display:none;max-height:0;overflow:hidden;border:0;width:132px;height:auto;"><!--<![endif]--></a></td>'
            . '<td align="right" style="font:600 12px/1 ' . $font . ';color:#0E7C86;">Product update</td></tr></table></td></tr>'
            // the product hero, full width, linked to the film
            . '<tr><td style="padding:0;line-height:0;font-size:0;"><a href="' . $film . '"><img src="' . $S . '/email-icons/onetap/hero-v2.jpg" width="600" alt="One-Tap: today&rsquo;s code at the door, the parent scans, the right adult verified" style="display:block;width:100%;max-width:600px;height:auto;border:0;"></a></td></tr>'
            // intro
            . '<tr><td class="pad" style="padding:30px 40px 8px;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;"><tr><td class="pill" bgcolor="#E6F4F5" style="background:#E6F4F5;border-radius:999px;padding:6px 12px;font:600 11.5px/1 ' . $font . ';letter-spacing:.6px;color:' . $teal . ';text-transform:uppercase;">New in KiddieTrac</td></tr></table>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;"><tr><td valign="middle"><img src="' . $S . '/email-icons/run-up/mark-one-tap.png" width="40" height="40" alt="" style="display:block;border:0;border-radius:10px;"></td>'
            . '<td valign="middle" class="hd" style="padding-left:12px;font:600 14px/1.3 ' . $font . ';color:' . $navy . ';">One-Tap<br><span style="font-weight:400;color:' . $muted . ';">Contactless check-in and pick-up</span></td></tr></table>'
            . '<div class="hd" style="font:600 26px/1.3 ' . $font . ';color:' . $navy . ';letter-spacing:-.3px;margin:0 0 14px;">A calmer, safer start and end to every day</div>'
            . $p(($first ? $e($first) . ', drop-off' : 'Drop-off') . ' and pick-up are the busiest moments at any centre, and the moments when knowing exactly who is collecting a child matters most.')
            . $p('With One-Tap, parents scan their centre&rsquo;s daily code with the KiddieTrac app on their own phone. The educator sees who is at the door, with their photo, and the hand-off is recorded automatically.')
            . '</td></tr>'
            // film
            . '<tr><td class="pad" style="padding:10px 40px 30px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td class="col" width="200" valign="middle" style="padding:0 18px 0 0;"><a href="' . $film . '" style="display:block;text-decoration:none;"><img src="' . $S . '/videos/one-tap/og-play.jpg" width="200" alt="Watch the One-Tap film" style="display:block;width:200px;max-width:100%;height:auto;border:0;border-radius:10px;"></a></td>'
            . '<td class="col" valign="middle"><div class="hd" style="font:600 17px/1.35 ' . $font . ';color:' . $navy . ';margin:0 0 6px;">Watch the 2-minute film</div>'
            . '<div style="font:400 14px/1.6 ' . $font . ';color:' . $body . ';margin:0 0 12px;">See a real drop-off and pick-up with One-Tap.</div>' . $btn('Watch the film', $film) . '</td>'
            . '</tr></table></td></tr>'
            . $hr
            // why
            . '<tr><td class="pad" style="padding:30px 40px 12px;">' . $label('Why it matters') . $h2('Every hand-off, confirmed and recorded')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:8px;">' . $whyRows . '</table></td></tr>'
            . $hr
            // how
            . '<tr><td class="pad" style="padding:30px 40px 12px;">' . $label('How it works') . $h2('Four simple steps')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:10px;">' . $stepRows . '</table></td></tr>'
            . $hr
            // who
            . '<tr><td class="pad" style="padding:30px 40px 18px;">' . $label('Learn more') . $h2('Interested in One-Tap?')
            . $p('Speak with your educator, centre or childcare agency today.', 'margin-bottom:16px;')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
            . $who('Parents and families', 'Ask your centre or childcare agency about One-Tap. We&rsquo;ve drafted a short note you can send.', $link('Ask my centre', $askParent))
            . $who('Educators and staff', 'Spend drop-off with the children rather than the clipboard. Ask your director or agency to switch it on.', $link('Ask my director', $askEducator))
            . $who('Directors and agencies', 'Already using KiddieTrac? One-Tap can be set up in minutes with a printed poster or a door tablet. New to KiddieTrac? We&rsquo;d be glad to show you.', $link('Book a demo', $S . '/?book=1'))
            . '</table></td></tr>'
            // sign-off
            // The app and the Google review (2026-10-27)
            . '<tr><td class="pad" style="padding:0 40px 18px;">' . PlayBadge::card('font-family:' . $font . ';') . '</td></tr>'
            // "Let's chat" (2026-10-27: it is sent from a no-reply address, so no "reply to this email")
            . '<tr><td class="pad" style="padding:8px 40px 34px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="card chat" bgcolor="#F1F8F9" style="background:#F1F8F9;border:1px solid #D8ECEE;border-radius:12px;padding:22px 24px;">'
            . '<div class="hd" style="font:600 18px/1.35 ' . $font . ';color:' . $navy . ';margin:0 0 6px;">Let&rsquo;s chat</div>'
            . '<div style="font:400 14.5px/1.6 ' . $font . ';color:' . $body . ';margin:0 0 14px;">Curious how One-Tap would work at your centre? We&rsquo;d love to talk it through with you.</div>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td>' . $btn('Let&rsquo;s chat', 'mailto:info@kiddietrac.com?subject=' . rawurlencode("Let's chat about One-Tap")) . '</td>'
            . '<td style="padding-left:14px;font:400 14px/1.4 ' . $font . ';color:' . $body . ';">or call <a href="tel:18554009996" style="color:' . $teal . ';text-decoration:none;font-weight:600;">1-855-400-9996</a></td></tr></table>'
            . '</td></tr></table>'
            . '<div class="hd" style="font:600 14.5px/1.5 ' . $font . ';color:' . $navy . ';margin:22px 0 0;">The KiddieTrac team</div></td></tr>'
            // footer
            . '<tr><td class="pad foot" bgcolor="#F8FAFC" style="background:#F8FAFC;border-top:1px solid ' . $line . ';padding:18px 40px 22px;font:400 12px/1.7 ' . $font . ';color:' . $muted . ';">'
            . 'You&rsquo;re receiving this because you use KiddieTrac or subscribed to KiddieTrac updates. '
            . '<a href="' . $e($unsub) . '" style="color:' . $muted . ';text-decoration:underline;">Unsubscribe</a> &middot; <a href="' . $S . '/privacy" style="color:' . $muted . ';text-decoration:underline;">Privacy</a><br>'
            . 'KiddieTrac &middot; 246372 Hockley Road, Mono, ON L9W 6K4, Canada'
            . '<div style="margin:10px 0 0;">' . SocialLinks::block(SocialLinks::row($muted, 'font-family:Arial,Helvetica,sans-serif;', 'Follow us', $muted, 'left')) . '</div></td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /* PLAY SPARK (2026-11-24, Anthony: "create new platform campaign to all subscribers on play spark - set this up
       for me and I will send this on my own"). Built like One-Tap's: white sheet, wordmark header, the product hero
       linked to the film. Play Spark's own palette: midnight ink #0B1026 / #1B2350 and spark gold #FFC531 (#9A5B00 for
       gold text on white). Plain, direct copy (no AI tone). Dark mode: prefers-color-scheme + [data-ogsc]/[data-ogsb]. */
    private static function renderPlaySpark(string $email, string $name = '', ?string $unsubUrl = null): string
    {
        $first = trim((string) strtok(trim($name), ' '));
        $unsub = $unsubUrl ?: LeadWelcome::unsubscribeUrl($email);
        $S = self::SITE;
        $film = $S . '/watch/play-spark/';
        $demo = $S . '/?book=1';
        $font = '-apple-system,Segoe UI,Helvetica,Arial,sans-serif';
        $ink = '#0B1026'; $gold = '#FFC531'; $goldT = '#9A5B00'; $body = '#4B5563'; $muted = '#8A94A3'; $line = '#E6EAF0';
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $p = fn ($h, $x = '') => '<p style="margin:0 0 14px;font:400 15px/1.7 ' . $font . ';color:' . $body . ';' . $x . '">' . $h . '</p>';
        $label = fn ($t) => '<div class="gold" style="font:600 11px/1 ' . $font . ';letter-spacing:1.4px;text-transform:uppercase;color:' . $goldT . ';margin:0 0 10px;">' . $t . '</div>';
        $h2 = fn ($t) => '<div class="hd" style="font:600 20px/1.35 ' . $font . ';color:' . $ink . ';margin:0 0 10px;">' . $t . '</div>';
        $btn = fn ($l, $u) => '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td class="btn" bgcolor="' . $gold . '" style="border-radius:8px;background:' . $gold . ';">'
            . '<a href="' . $e($u) . '" style="display:inline-block;white-space:nowrap;padding:12px 20px;font:700 14px/1 ' . $font . ';color:' . $ink . ' !important;text-decoration:none;border-radius:8px;">' . $l . '</a></td></tr></table>';
        $btn2 = fn ($l, $u) => '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td class="btn2" bgcolor="' . $ink . '" style="border-radius:8px;background:' . $ink . ';">'
            . '<a href="' . $e($u) . '" style="display:inline-block;white-space:nowrap;padding:12px 20px;font:700 14px/1 ' . $font . ';color:#FFFFFF !important;text-decoration:none;border-radius:8px;">' . $l . '</a></td></tr></table>';
        $hr = '<tr><td class="pad" style="padding:0 40px;"><div class="hr" style="height:1px;line-height:1px;background:' . $line . ';">&nbsp;</div></td></tr>';

        $points = [
            ['Ideas for the kids in the room', 'It uses the ages of the children signed in right now, so every idea comes with a version for each age group.'],
            ['Works with what you have', 'Snap a photo of the shelf and the idea uses those materials. Or type what you have.'],
            ['Logged when you&rsquo;re done', 'Tap &ldquo;We did it&rdquo; and it goes on each child&rsquo;s day, with a draft observation ready for you to finish.'],
            ['Families can try it at home', 'Parents see what their child did and get a simple version to try at home.'],
        ];
        $rows = '';
        foreach (array_chunk($points, 2) as $row) {
            $rows .= '<tr>';
            foreach ($row as $i => $w) {
                $rows .= '<td class="col" width="50%" valign="top" style="padding:' . ($i ? '0 0 18px 12px' : '0 12px 18px 0') . ';">'
                    . '<div class="bar" style="width:24px;height:3px;background:' . $gold . ';margin:0 0 10px;font-size:0;line-height:0;">&nbsp;</div>'
                    . '<div class="hd" style="font:600 15px/1.4 ' . $font . ';color:' . $ink . ';margin:0 0 4px;">' . $w[0] . '</div>'
                    . '<div style="font:400 14px/1.6 ' . $font . ';color:' . $body . ';">' . $w[1] . '</div></td>';
            }
            $rows .= '</tr>';
        }

        $dark = '.body-bg{background:#0E1525 !important}.sheet{background:#141D2F !important}.card{border-color:#2A3650 !important}'
            . '.sheet div,.sheet p,.sheet td{color:#C9D3E0 !important}.sheet .hd{color:#F1F5F9 !important}.sheet .gold{color:#FFC531 !important}'
            . '.sheet td.top{background:#141D2F !important}.hr{background:#2A3650 !important}.logo-l{display:none !important}.logo-d{display:block !important;max-height:none !important}'
            . '.sheet td.foot{background:#0F1828 !important;border-color:#2A3650 !important;color:#8A97AB !important}.sheet td.foot a{color:#9FB0C6 !important}'
            . '.sheet td.pill{background:#2A2410 !important;color:#FFC531 !important}.sheet td.chat{background:#18213A !important;border-color:#2A3650 !important}'
            . '.sheet td.btn{background:#FFC531 !important}.sheet td.btn2{background:#2A3A66 !important}'
            . '.sheet .keep,.sheet .keep *{color:#FFFFFF !important}.sheet .keep2,.sheet .keep .keep2{color:#C9CFEA !important}.sheet td.banner{background-color:#0B1026 !important}';
        $ogsc = preg_replace('/(^|\}|,)\s*\./','$1[data-ogsc] .', $dark);
        $ogsb = '[data-ogsb] .body-bg{background:#0E1525 !important}[data-ogsb] .sheet{background:#141D2F !important}[data-ogsb] .sheet td.top{background:#141D2F !important}'
            . '[data-ogsb] .sheet td.foot{background:#0F1828 !important}[data-ogsb] .sheet td.chat{background:#18213A !important}[data-ogsb] .sheet td.pill{background:#2A2410 !important}'
            . '[data-ogsb] .sheet td.btn{background:#FFC531 !important}[data-ogsb] .sheet td.btn2{background:#2A3A66 !important}';

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark"><title>' . $e(self::subject('playspark')) . '</title>'
            . '<style>@media (max-width:620px){.wrap{width:100% !important}.pad{padding-left:22px !important;padding-right:22px !important}.col{display:block !important;width:100% !important;padding:0 0 16px !important}.ctas td.cta{display:block !important;padding:0 0 10px !important}.col img{width:100% !important;max-width:100% !important}h1{font-size:26px !important}}'
            . '@media (prefers-color-scheme:dark){' . $dark . '}' . $ogsc . $ogsb . '</style></head>'
            . '<body class="body-bg" style="margin:0;padding:0;background:#F3F5F8;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $e(self::PREHEADER['playspark']) . '&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="body-bg" bgcolor="#F3F5F8" style="background:#F3F5F8;"><tr><td align="center" style="padding:28px 12px;">'
            . '<table role="presentation" class="wrap sheet" width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="#FFFFFF" style="width:600px;max-width:600px;background:#ffffff;border:1px solid ' . $line . ';border-radius:12px;overflow:hidden;">'
            // header: the four toy colours as a slim bar (solid cells, no gradient-only background), wordmark, "Product update"
            . '<tr><td style="padding:0;font-size:0;line-height:0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td width="25%" bgcolor="#FF5A4E" style="height:4px;background:#FF5A4E;">&nbsp;</td><td width="25%" bgcolor="#FFC531" style="height:4px;background:#FFC531;">&nbsp;</td>'
            . '<td width="25%" bgcolor="#3D8BFF" style="height:4px;background:#3D8BFF;">&nbsp;</td><td width="25%" bgcolor="#2FCB8E" style="height:4px;background:#2FCB8E;">&nbsp;</td></tr></table></td></tr>'
            /* HEADER v2 (2026-11-24, Anthony: "create a better header for the email and add some real life image"): a
               midnight banner (solid bgcolor + gradient, never gradient-only) with the small white KiddieTrac wordmark,
               the Play Spark mark and name, and the headline as LIVE text; then a real photo fading up into it. */
            . '<tr><td class="pad banner" bgcolor="#0B1026" style="background-color:#0B1026;background-image:linear-gradient(160deg,#0B1026 0%,#1B2350 100%);padding:22px 40px 26px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td valign="middle"><a href="' . $S . '/"><img src="' . $S . '/email-icons/brand/kiddietrac-wordmark.png" width="104" alt="KiddieTrac" style="display:block;border:0;width:104px;height:auto;"></a></td>'
            . '<td align="right" valign="middle" class="gold" style="font:700 11px/1 ' . $font . ';letter-spacing:1.2px;text-transform:uppercase;color:#FFC531;">New in KiddieTrac</td></tr></table>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:26px 0 14px;"><tr><td valign="middle"><img src="' . $S . '/email-icons/playspark/mark.png" width="44" height="44" alt="" style="display:block;border:0;border-radius:11px;"></td>'
            . '<td valign="middle" class="keep" style="padding-left:12px;font:700 17px/1.25 ' . $font . ';color:#FFFFFF;">Play Spark<br><span class="keep2" style="font-weight:400;font-size:13px;color:#C9CFEA;">On-the-spot play ideas</span></td></tr></table>'
            . '<h1 class="keep" style="margin:0 0 10px;font:800 30px/1.2 ' . $font . ';color:#FFFFFF;letter-spacing:-.4px;">One tap. A play idea for every age in the room.</h1>'
            . '<div class="keep2" style="font:400 15px/1.6 ' . $font . ';color:#C9CFEA;">For the moments when nothing&rsquo;s planned and the kids are ready to go.</div>'
            . '</td></tr>'
            . '<tr><td bgcolor="#0B1026" style="padding:0;line-height:0;font-size:0;background:#0B1026;"><img src="' . $S . '/email-icons/playspark/hero-photo.jpg" width="600" alt="An educator leading an activity with young children at a table" style="display:block;width:100%;max-width:600px;height:auto;border:0;"></td></tr>'
            // intro
            . '<tr><td class="pad" style="padding:30px 40px 8px;">'
            . $p(($first ? $e($first) . ', you' : 'You') . ' know the moment. It&rsquo;s raining, six kids are restless, and nothing&rsquo;s planned for the next twenty minutes.')
            . $p('Open Play Spark and tap once. You get an activity for the children signed in right now, with a version for each age. Snap a photo of what&rsquo;s on the shelf and it works with what you have.')
            . $p('When you&rsquo;re done, tap &ldquo;We did it&rdquo;. It goes on each child&rsquo;s day, and parents get an easy idea to try at home.')
            // the film poster (▶), then the two buttons
            . '<a href="' . $film . '" style="display:block;text-decoration:none;margin:8px 0 18px;"><img src="' . $S . '/email-icons/playspark/hero-play.jpg" width="520" alt="Watch the 2-minute Play Spark film" style="display:block;width:100%;max-width:520px;height:auto;border:0;border-radius:12px;"></a>'
            . '<table role="presentation" class="ctas" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px;"><tr><td class="cta" style="padding:0 12px 0 0;">' . $btn('Watch the 2-minute film', $film) . '</td>'
            . '<td class="cta">' . $btn2('Book a demo', $demo) . '</td></tr></table>'
            . '</td></tr>'
            . '<tr><td style="height:22px;line-height:22px;font-size:0;">&nbsp;</td></tr>'
            . $hr
            // how it helps: a real photo, the four points, then two photos (outdoors / at home)
            . '<tr><td class="pad" style="padding:30px 40px 12px;">' . $label('How it works') . $h2('Less planning on the fly, more time with the kids')
            . '<img src="' . $S . '/email-icons/playspark/play-blocks.jpg" width="520" alt="A child building with colourful blocks" style="display:block;width:100%;max-width:520px;height:auto;border:0;border-radius:12px;margin:6px 0 18px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:8px;">' . $rows . '</table>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 8px;"><tr>'
            . '<td class="col" width="50%" valign="top" style="padding:0 8px 0 0;"><img src="' . $S . '/email-icons/playspark/play-outdoors.jpg" width="252" alt="An adult and a toddler drawing with chalk outside" style="display:block;width:100%;max-width:252px;height:auto;border:0;border-radius:12px;">'
            . '<div style="font:400 13px/1.5 ' . $font . ';color:' . $muted . ';margin:8px 0 0;">Indoors or out, with what you have.</div></td>'
            . '<td class="col" width="50%" valign="top" style="padding:0 0 0 8px;"><img src="' . $S . '/email-icons/playspark/home-together.jpg" width="252" alt="A parent reading with a young child at home" style="display:block;width:100%;max-width:252px;height:auto;border:0;border-radius:12px;">'
            . '<div style="font:400 13px/1.5 ' . $font . ';color:' . $muted . ';margin:8px 0 0;">And a version families can try at home.</div></td>'
            . '</tr></table></td></tr>'
            . $hr
            // lesson plans
            . '<tr><td class="pad" style="padding:30px 40px 12px;">' . $label('Also new') . $h2('Your lesson plans live here now')
            . $p('Weekly lesson plans moved into Play Spark. Plan the week, keep a library of activities that worked, and publish the week to families from the same screen. A good idea from today can go straight onto next week&rsquo;s plan.')
            . $p('Every idea is checked for safety before you see it, with extra care for children under three and a note when food is involved. Only the children&rsquo;s ages and counts are used, never their names.')
            . $p('The idea tools are part of the AI Bundle add-on. Lesson plans work on every plan.', 'font-size:14px;color:' . $muted . ';')
            . '</td></tr>'
            // app badge + chat
            . '<tr><td class="pad" style="padding:6px 40px 18px;">' . PlayBadge::card('font-family:' . $font . ';') . '</td></tr>'
            . '<tr><td class="pad" style="padding:8px 40px 34px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="card chat" bgcolor="#F6F7FB" style="background:#F6F7FB;border:1px solid ' . $line . ';border-radius:12px;padding:22px 24px;">'
            . '<div class="hd" style="font:600 18px/1.35 ' . $font . ';color:' . $ink . ';margin:0 0 6px;">Want to see it in your room?</div>'
            . '<div style="font:400 14.5px/1.6 ' . $font . ';color:' . $body . ';margin:0 0 14px;">We&rsquo;ll show you Play Spark and lesson plans with your own rooms and ages. It takes about 20 minutes.</div>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td>' . $btn2('Book a demo', $demo) . '</td>'
            . '<td style="padding-left:14px;font:400 14px/1.4 ' . $font . ';color:' . $body . ';">or call <a href="tel:18554009996" class="gold" style="color:' . $goldT . ';text-decoration:none;font-weight:600;">1-855-400-9996</a></td></tr></table>'
            . '</td></tr></table>'
            . '<div class="hd" style="font:600 14.5px/1.5 ' . $font . ';color:' . $ink . ';margin:22px 0 0;">The KiddieTrac team</div></td></tr>'
            // footer
            . '<tr><td class="pad foot" bgcolor="#F8FAFC" style="background:#F8FAFC;border-top:1px solid ' . $line . ';padding:18px 40px 22px;font:400 12px/1.7 ' . $font . ';color:' . $muted . ';">'
            . 'You&rsquo;re receiving this because you use KiddieTrac or subscribed to KiddieTrac updates. '
            . '<a href="' . $e($unsub) . '" style="color:' . $muted . ';text-decoration:underline;">Unsubscribe</a> &middot; <a href="' . $S . '/privacy" style="color:' . $muted . ';text-decoration:underline;">Privacy</a><br>'
            . 'KiddieTrac &middot; 246372 Hockley Road, Mono, ON L9W 6K4, Canada'
            . '<div style="margin:10px 0 0;">' . SocialLinks::block(SocialLinks::row($muted, 'font-family:Arial,Helvetica,sans-serif;', 'Follow us', $muted, 'left')) . '</div></td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /* HALLOWEEN (2026-10-27, Anthony: "the halloween marketing email - draft this up and send to me to approve and
       scheduled for this friday"). The Halloween film, quality time together, a thank-you to educators, a few
       trick-or-treat safety tips. White header with the colour wordmark, an orange accent. */
    private static function renderHalloween(string $email, string $name = '', ?string $unsubUrl = null): string
    {
        $first = trim((string) strtok(trim($name), ' '));
        $unsub = $unsubUrl ?: LeadWelcome::unsubscribeUrl($email);
        $S = self::SITE;
        $film = $S . '/watch/halloween/';
        $font = '-apple-system,Segoe UI,Helvetica,Arial,sans-serif';
        $navy = '#FFB25E'; $orange = '#F97316'; $purple = '#A78BFA'; $body = '#E2D6F3'; $muted = '#A99BC6'; $line = '#3A2462';   // night palette: headings pumpkin, text lavender
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $p = fn ($h, $x = '') => '<p style="margin:0 0 14px;font:400 15px/1.7 ' . $font . ';color:' . $body . ';' . $x . '">' . $h . '</p>';
        $label = fn ($t) => '<div style="font:600 11px/1 ' . $font . ';letter-spacing:1.4px;text-transform:uppercase;color:' . $orange . ';margin:0 0 10px;">' . $t . '</div>';
        $h2 = fn ($t) => '<div class="hd" style="font:600 20px/1.35 ' . $font . ';color:' . $navy . ';margin:0 0 12px;">' . $t . '</div>';
        $btn = fn ($l, $u) => '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td bgcolor="' . $orange . '" style="border-radius:8px;background:' . $orange . ';">'
            . '<a href="' . $e($u) . '" style="display:inline-block;padding:12px 22px;font:600 14.5px/1 ' . $font . ';color:#ffffff !important;text-decoration:none;border-radius:8px;">' . $l . '</a></td></tr></table>';
        // bats-and-pumpkin divider instead of a plain rule
        $hr = '<tr><td class="pad" align="center" style="padding:4px 40px;font:400 16px/1 ' . $font . ';color:#7C5BB5;letter-spacing:10px;">&#129415; &#127875; &#129415;</td></tr>';

        $ideas = [
            ['🎃', 'Decorate a pumpkin together', 'Paint, stickers or a carved face &mdash; little hands love to help design it.'],
            ['👻', 'A costume parade at home', 'Put on a song, dim the lights and let everyone show off their costume.'],
            ['📖', 'A not-too-spooky bedtime story', 'Snuggle up with a friendly ghost or a brave little witch before lights out.'],
            ['🍂', 'A twilight walk to see the decorations', 'Count the jack-o&rsquo;-lanterns and wave to the neighbours along the way.'],
        ];
        $ideaRows = '';
        foreach (array_chunk($ideas, 2) as $row) {
            $ideaRows .= '<tr>';
            foreach ($row as $i => $w) {
                $ideaRows .= '<td class="col" width="50%" valign="top" style="padding:' . ($i ? '0 0 14px 7px' : '0 7px 14px 0') . ';">'
                    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="card" bgcolor="#251443" style="background:#251443;border:1px solid #3D2470;border-radius:12px;padding:16px;">'
                    . '<div style="font-size:24px;line-height:1;margin:0 0 8px;">' . $w[0] . '</div>'
                    . '<div class="hd" style="font:600 15px/1.35 ' . $font . ';color:' . $navy . ';margin:0 0 4px;">' . $w[1] . '</div>'
                    . '<div style="font:400 13.5px/1.55 ' . $font . ';color:' . $body . ';">' . $w[2] . '</div></td></tr></table></td>';
            }
            $ideaRows .= '</tr>';
        }
        $tips = ['Add reflective tape or a glow stick to costumes so drivers can see little ones.', 'Choose face paint over masks so children can see clearly.',
            'Stay together and keep to well-lit streets.', 'Check treats at home before anything is eaten &mdash; and mind any allergies.'];
        $tipRows = '';
        foreach ($tips as $t) {
            $tipRows .= '<tr><td width="22" valign="top" style="padding:0 0 9px;font:700 14px/1.6 ' . $font . ';color:' . $orange . ';">&#10003;</td>'
                . '<td valign="top" style="padding:0 0 9px;font:400 14.5px/1.6 ' . $font . ';color:' . $body . ';">' . $t . '</td></tr>';
        }

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark"><title>' . $e(self::subject('halloween')) . '</title>'
            . '<style>@media (max-width:620px){.wrap{width:100% !important}.pad{padding-left:22px !important;padding-right:22px !important}.col{display:block !important;width:100% !important;padding:0 0 12px !important}}'
            . '</style></head>'
            . '<body class="body-bg" style="margin:0;padding:0;background:#0D0620;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">A little Halloween film for every family and educator &mdash; and a few ideas for spooky-fun time together.&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="body-bg" bgcolor="#0D0620" style="background:#0D0620;"><tr><td align="center" style="padding:28px 12px;">'
            . '<table role="presentation" class="wrap sheet" width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="#1A0E33" style="width:600px;max-width:600px;background:#1A0E33;border:1px solid ' . $line . ';border-radius:14px;overflow:hidden;">'
            // the spooky banner (moon, bats, cobweb, jack-o'-lanterns, the wordmark) - one image
            . '<tr><td style="padding:0;line-height:0;font-size:0;" bgcolor="#0D0620"><a href="' . $film . '"><img src="' . $S . '/email-icons/halloween/banner.jpg" width="600" alt="Happy Halloween from all of us at KiddieTrac" style="display:block;width:100%;max-width:600px;height:auto;border:0;"></a></td></tr>'
            // film still
            . '<tr><td class="pad" style="padding:26px 40px 0;"><a href="' . $film . '" style="display:block;text-decoration:none;"><img src="' . $S . '/videos/halloween/og-play.jpg" width="520" alt="Watch our Halloween film" style="display:block;width:100%;max-width:520px;height:auto;border:0;border-radius:12px;box-shadow:0 0 0 1px #3D2470;"></a></td></tr>'
            // intro
            . '<tr><td class="pad" style="padding:28px 40px 26px;">'
            . '<div class="hd" style="font:600 26px/1.3 ' . $font . ';color:' . $navy . ';letter-spacing:-.2px;margin:0 0 14px;">A spook-tacular week at your centre &#127875;</div>'
            . $p(($first ? $e($first) . ', this' : 'This') . ' week is full of costumes, pumpkins and very excited little ones. We made a short film to celebrate it &mdash; from Halloween drop-off and a pumpkin-patch walk with the educators, to the best part of the day: <b>home time together</b>.')
            . $p('Thank you to every family for sharing your children with your centre, and to every educator for making their days so special.')
            . $btn('&#9654;&nbsp; Watch the Halloween film', $film) . '</td></tr>'
            . $hr
            // quality time
            . '<tr><td class="pad" style="padding:28px 40px 14px;">' . $label('Halloween together') . $h2('Spooky-fun ideas for this weekend')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:4px;">' . $ideaRows . '</table></td></tr>'
            // educators
            . '<tr><td class="pad" style="padding:6px 40px 26px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="card thanks" bgcolor="#2B1452" style="background:#2B1452;border:1px solid #4C2D86;border-radius:12px;padding:20px 22px;">'
            . '<div class="hd" style="font:600 16px/1.4 ' . $font . ';color:' . $navy . ';margin:0 0 6px;">&#129497; A special thank-you to our educators</div>'
            . '<div style="font:400 14.5px/1.65 ' . $font . ';color:' . $body . ';">Witch hats, pumpkin crafts, costume parades and a hundred tiny zippers &mdash; you make Halloween magic for the children every year. Thank you.</div>'
            . '</td></tr></table></td></tr>'
            . $hr
            // safety
            . '<tr><td class="pad" style="padding:28px 40px 18px;">' . $label('Safe and happy') . $h2('Trick-or-treat tips')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $tipRows . '</table></td></tr>'
            // app + review
            . '<tr><td class="pad" style="padding:8px 40px 18px;">' . PlayBadge::card('font-family:' . $font . ';') . '</td></tr>'
            // sign-off
            . '<tr><td class="pad" style="padding:10px 40px 32px;">' . $p('Wishing you a safe, happy &mdash; and just a little bit spooky &mdash; Halloween,', 'margin:0 0 4px;')
            . '<div class="hd" style="font:600 15px/1.5 ' . $font . ';color:' . $navy . ';">The KiddieTrac team</div></td></tr>'
            // footer
            . '<tr><td class="pad foot" bgcolor="#130A27" style="background:#130A27;border-top:1px solid ' . $line . ';padding:18px 40px 22px;font:400 12px/1.7 ' . $font . ';color:' . $muted . ';">'
            . 'You&rsquo;re receiving this because you use KiddieTrac or subscribed to KiddieTrac updates. '
            . '<a href="' . $e($unsub) . '" style="color:' . $muted . ';text-decoration:underline;">Unsubscribe</a> &middot; <a href="' . $S . '/privacy" style="color:' . $muted . ';text-decoration:underline;">Privacy</a><br>'
            . 'KiddieTrac &middot; 246372 Hockley Road, Mono, ON L9W 6K4, Canada'
            . '<div style="margin:10px 0 0;">' . SocialLinks::block(SocialLinks::row($muted, 'font-family:Arial,Helvetica,sans-serif;', 'Follow us', $muted, 'left')) . '</div></td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /** Who it goes to: subscribed, not suppressed, not already sent this campaign. */
    public static function audience(string $key): \Illuminate\Support\Collection
    {
        return DB::table('site_subscribers')->whereNull('unsubscribed_at')->orderBy('id')->get(['id', 'email', 'name'])
            ->filter(fn ($r) => filter_var($r->email, FILTER_VALIDATE_EMAIL) && ! SalesCampaigns::suppressed((string) $r->email))
            ->filter(fn ($r) => ! Schema::hasTable('subscriber_campaign_sends') || ! DB::table('subscriber_campaign_sends')->where('campaign', $key)->where('email', strtolower($r->email))->exists())
            ->values();
    }

    /** One copy. $test: a [TEST] copy to that address (not logged, not deduplicated). */
    public static function sendOne(string $key, string $email, string $name = '', bool $test = false): bool
    {
        $html = self::render($key, $email, $name);
        $unsub = LeadWelcome::unsubscribeUrl($email);
        try {
            $went = Mail::html($html, function ($m) use ($key, $email, $name, $test, $unsub) {
                MailScope::platform($m);
                $m->to($email, $test ? null : ($name ?: null))->subject(($test ? '[TEST] ' : '') . self::subject($key))->replyTo('info@kiddietrac.com', 'KiddieTrac');
                $h = $m->getHeaders();
                $h->addTextHeader('X-KT-Campaign', 'subscribers-' . $key);   // SuppressAgencyMail: only the unsubscribe list applies
                $h->addTextHeader('List-Unsubscribe', '<' . $unsub . '>, <mailto:info@kiddietrac.com?subject=unsubscribe>');
                $h->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
            if ($went === null) return false;   // cancelled by the mail gate: not sent, not recorded (2026-11-11)
            if (! $test && Schema::hasTable('subscriber_campaign_sends')) {
                DB::table('subscriber_campaign_sends')->insert(['campaign' => $key, 'email' => strtolower($email), 'sent_at' => now()]);
            }
            return true;
        } catch (\Throwable $e) {
            Log::warning('subscriber campaign send failed', ['campaign' => $key, 'to' => $email, 'error' => $e->getMessage()]);
            return false;
        }
    }
}
