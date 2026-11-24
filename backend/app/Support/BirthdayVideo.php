<?php
declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\URL;

/**
 * The birthday sing-along (2026-10-21): links to it, and the card that offers it in every
 * birthday email.
 *
 * ONE cartoon for every birthday. It never shows or says a name (Anthony: "the video should
 * not have the name of the person itself and keep generic but outside of the video can mention
 * names"). The name lives only around it: in the email wording, and in the greeting above the
 * player, which the page fetches with a signed, expiring link (BirthdayVideoController: first
 * name, age, centre/agency names, nothing else).
 *
 *   BirthdayVideo::childUrl($childId, $turning)       page + signed greeting for a child
 *   BirthdayVideo::staffUrl($userId)                   page + signed greeting for a staff member
 *   BirthdayVideo::PAGE                                the song on its own, no greeting
 *   BirthdayVideo::emailBlock($kind, $firstName, $href, ['centre' => ..])
 *       kinds: child_educator, child_guardian, staff_person, staff_lead
 */
final class BirthdayVideo
{
    public const PAGE = 'https://app.kiddietrac.com/birthday/';
    public const THUMB = 'https://app.kiddietrac.com/birthday/thumb.jpg';

    public static function childUrl(int $childId, int $turning, int $days = 14): string
    {
        $signed = URL::temporarySignedRoute('birthday.video', now()->addDays(max(1, min(30, $days))), [
            'child' => $childId,
            'n' => max(0, min(12, $turning)),
        ]);
        $q = [];
        parse_str((string) parse_url($signed, PHP_URL_QUERY), $q);

        return self::PAGE . '?' . http_build_query([
            'id' => $childId, 'expires' => $q['expires'] ?? '', 'n' => $q['n'] ?? '', 'signature' => $q['signature'] ?? '',
        ]);
    }

    public static function staffUrl(int $userId, int $days = 14): string
    {
        $signed = URL::temporarySignedRoute('birthday.video.staff', now()->addDays(max(1, min(30, $days))), ['user' => $userId]);
        $q = [];
        parse_str((string) parse_url($signed, PHP_URL_QUERY), $q);

        return self::PAGE . '?' . http_build_query([
            'k' => 's', 'id' => $userId, 'expires' => $q['expires'] ?? '', 'signature' => $q['signature'] ?? '',
        ]);
    }

    /** The sample greeting (Amara, 4, Sunny Meadows) used by test emails. */
    public static function demoUrl(): string
    {
        return self::PAGE . '?id=demo';
    }

    /**
     * The card in a birthday email, worded for who is reading it. Table-based and inline-styled
     * so it holds up in Gmail, Outlook (bgcolor for the dark panel) and on a phone.
     */
    public static function emailBlock(string $kind, string $firstName, string $href, array $opts = []): string
    {
        $n = e(trim($firstName) !== '' ? trim($firstName) : 'them');
        $centre = trim((string) ($opts['centre'] ?? ''));
        $from = e($centre !== '' ? $centre : 'the team');

        switch ($kind) {
            case 'child_guardian':
                $eyebrow = '🎬 A BIRTHDAY SONG FROM ALL OF US';
                $title = 'A little song for ' . $n;
                $text = 'A one-minute birthday cartoon with the whole Happy Birthday song, from ' . $n
                    . '’s friends and teachers and from everyone at KiddieTrac.';
                $button = '▶ Play the birthday song';
                $tip = 'Play it together at home and sing along. The words light up as the song plays.';
                break;
            case 'staff_person':
                $eyebrow = '🎬 A BIRTHDAY SONG FOR YOU';
                $title = 'Happy birthday, ' . $n . '!';
                $text = 'A little sing-along to start your day, with happy birthday wishes from everyone at '
                    . $from . ' and from all of us at KiddieTrac.';
                $button = '▶ Play your birthday song';
                $tip = 'Turn the sound up and enjoy your day!';
                break;
            case 'staff_lead':
                $eyebrow = '🎬 A BIRTHDAY SING-ALONG FOR THE TEAM';
                $title = 'Celebrate ' . $n . '’s birthday';
                $text = 'A one-minute birthday cartoon with the whole song. Play it at a team huddle or share the link with ' . $n . '.';
                $button = '▶ Play the birthday song';
                $tip = 'It works on a TV, a tablet or a phone, and needs no sign-in.';
                break;
            case 'child_educator':
            default:
                $eyebrow = '🎬 A BIRTHDAY SING-ALONG FOR YOUR ROOM';
                $title = 'Sing Happy Birthday to ' . $n;
                $text = 'A one-minute cartoon with the whole song and the words on screen, so the whole room can sing along for ' . $n . '.';
                $button = '▶ Play the birthday video';
                $tip = '<strong style="color:#FFFFFF;">Tip:</strong> play it in class so ' . $n
                    . ' can hear everyone sing! It works on the classroom TV, a tablet or a phone, and needs no sign-in.';
                break;
        }
        $href = e($href);

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 8px;border-collapse:separate;">'
            . '<tr><td bgcolor="#0B2545" style="background:#0B2545;border-radius:16px;padding:0;">'
            . '<a href="' . $href . '" style="display:block;text-decoration:none;">'
            . '<img src="' . self::THUMB . '" width="560" alt="▶ Play the birthday sing-along" '
            . 'style="display:block;width:100%;max-width:560px;height:auto;border:0;border-radius:16px 16px 0 0;"></a>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td style="padding:18px 22px 22px;text-align:center;font-family:Arial,Helvetica,sans-serif;">'
            . '<div style="color:#FFCF3F;font-size:12px;font-weight:bold;letter-spacing:1.2px;">' . $eyebrow . '</div>'
            . '<div style="color:#FFFFFF;font-size:21px;font-weight:bold;line-height:1.3;margin:8px 0 6px;">' . $title . '</div>'
            . '<div style="color:#C7D6E8;font-size:14px;line-height:1.55;margin:0 0 16px;">' . $text . '</div>'
            . '<a href="' . $href . '" style="display:inline-block;background:#FFCF3F;color:#0B2545;font-weight:bold;font-size:16px;'
            . 'padding:13px 28px;border-radius:999px;text-decoration:none;">' . $button . '</a>'
            . '<div style="color:#C7D6E8;font-size:13px;line-height:1.5;margin-top:16px;">💡 ' . $tip . '</div>'
            . '</td></tr></table>'
            . '</td></tr></table>';
    }
}
