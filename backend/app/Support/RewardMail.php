<?php

namespace App\Support;

use App\Services\EmailTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The "you've earned a reward" email (2026-10-20).
 *
 * Anthony: "when sending award to educators it needs to send an announcement, email to that
 * educator and bcc admin/director (email needs to be designed nicely - send me a test to my
 * gmail".
 *
 * ONE message: To the educator, BCC the agency's admins and the director(s) of the
 * educator's centre(s), so the recipients never see each other. Real people only: system
 * inboxes, switched-off accounts, anyone suppressed and duplicates are dropped, and the
 * educator is never BCC'd on their own mail.
 *
 * NO CARD IN THE EMAIL. The admins on BCC would see it, so a gift card always says it is
 * waiting in KiddieTrac → My points; the code and link only ever show behind the
 * educator's own sign-in.
 *
 * Built inside EmailTemplate::wrap, so it carries the agency's branding, the navy header
 * band that survives blocked images, the Outlook-safe fixed width and the dark-mode
 * palette every other designed email uses. Everything below is tables and inline styles.
 */
final class RewardMail
{
    private const APP = 'https://app.kiddietrac.com/dashboard.html#my-points';

    /** The person the email is to, and who is BCC'd. */
    public static function recipients(int $agencyId, int $userId): array
    {
        $isPerson = fn ($e) => $e && filter_var($e, FILTER_VALIDATE_EMAIL)
            && ! preg_match('/^(noreply|no-reply)@|integration\+/i', $e);

        $edu = DB::table('users')->where('id', $userId)->first(['id', 'email', 'first_name', 'last_name']);
        $to = null;
        $skipped = [];
        if ($edu && $isPerson((string) $edu->email)) {
            if (Suppression::isUser($userId)) {
                Suppression::note('email', $userId, 'educator_reward');
                $skipped[] = 'educator (email turned off)';
            } else {
                $to = ['email' => strtolower(trim($edu->email)), 'name' => trim($edu->first_name . ' ' . $edu->last_name)];
            }
        } else {
            $skipped[] = 'educator (no email address)';
        }

        $centres = DB::table('role_assignments')->whereIn('role', \App\Support\Visibility::STAFF_ROLES)->where('user_id', $userId)->where('agency_id', $agencyId)
            ->where('active', 1)->whereNotNull('centre_id')->pluck('centre_id')->all();
        $rows = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', 1)->where('ra.agency_id', $agencyId)
            ->where(function ($q) use ($centres) {
                $q->where('ra.role', 'agency_admin')
                  ->orWhere(fn ($w) => $w->where('ra.role', 'centre_director')->whereIn('ra.centre_id', $centres ?: [0]));
            })
            ->whereNull('u.deleted_at')->whereNotIn('u.status', Audience::OFF_STATUSES)
            ->where('u.id', '!=', $userId)
            ->get(['u.id', 'u.email', 'u.first_name', 'u.last_name', 'ra.role']);

        $bcc = [];
        $seen = $to ? [$to['email'] => true] : [];
        foreach ($rows as $r) {
            $e = strtolower(trim((string) $r->email));
            if (! $isPerson($e) || isset($seen[$e])) {
                continue;
            }
            if (Suppression::isUser((int) $r->id)) {
                Suppression::note('email', (int) $r->id, 'educator_reward_bcc');
                continue;
            }
            $seen[$e] = true;
            $bcc[] = ['email' => $e, 'name' => trim($r->first_name . ' ' . $r->last_name), 'role' => $r->role];
        }

        return ['to' => $to, 'bcc' => $bcc, 'skipped' => $skipped];
    }

    /** What the reward is, in one line. */
    public static function rewardLine(object $a): array
    {
        $amt = '$' . number_format((float) $a->amount, 2);
        $brand = ($a->brand && $a->brand !== 'Their choice') ? $a->brand : null;

        return match ($a->method) {
            'payroll_bonus' => ['💵', $amt . ' bonus', 'Added to your next pay', false],
            'payout' => ['💸', $amt . ' payout', 'Paid to you directly', false],
            'gift_card_manual' => ['🎁', $amt . ' ' . ($brand ? $brand . ' ' : '') . 'gift card', 'Waiting for you in KiddieTrac → My points', true],
            'gift_card_provider' => ['🎁', $amt . ' ' . ($brand ? $brand . ' gift card' : 'gift card of your choice'), 'Waiting for you in KiddieTrac → My points', true],
            default => ['⭐', $amt . ' reward', '', false],
        };
    }

    /**
     * Subject + HTML. $ctx: first, by, month (YYYY-MM), points, by_activity [[icon,label,points]], note
     */
    public static function build(int $agencyId, object $a, array $ctx): array
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $month = Carbon::parse($ctx['month'] . '-01')->format('F Y');
        [$icon, $headline, $sub, $isCard] = self::rewardLine($a);
        $F = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;";

        // ── hero: navy → teal, with a solid bgcolor for Outlook ──
        $hero = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;margin:0 0 20px;">'
            . '<tr><td align="center" bgcolor="#0B2545" style="background-color:#0B2545;background-image:linear-gradient(135deg,#0B2545 0%,#13708A 100%);border-radius:16px;padding:30px 22px 26px;' . $F . '">'
            . '<div style="font-size:46px;line-height:1;margin:0 0 10px;">⭐</div>'
            . '<div style="font-size:12px;font-weight:800;letter-spacing:2px;color:#9FE3E1;text-transform:uppercase;">' . $e($month) . '</div>'
            . '<div style="font-size:26px;font-weight:900;line-height:1.2;color:#FFFFFF;margin-top:6px;">You’ve earned a reward, ' . $e($ctx['first']) . '!</div>'
            . '<div style="font-size:15px;line-height:1.5;color:#D8F1F3;margin-top:8px;">Thank you for everything you logged in KiddieTrac this month.</div>'
            . '</td></tr></table>';

        // ── the reward ──
        $reward = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;">'
            . '<tr><td class="kt-panel" style="background:#FFFBEB;border:2px solid #FCD34D;border-radius:14px;padding:18px 20px;' . $F . '">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td width="54" valign="middle" style="font-size:38px;line-height:1;">' . $icon . '</td>'
            . '<td valign="middle" style="' . $F . '"><div style="font-size:11px;font-weight:800;letter-spacing:1.5px;color:#B45309;text-transform:uppercase;">Your reward</div>'
            . '<div style="font-size:22px;font-weight:900;color:#0F172A;line-height:1.25;margin-top:2px;">' . $e($headline) . '</div>'
            . ($sub ? '<div style="font-size:13.5px;color:#475569;margin-top:3px;">' . $e($sub) . '</div>' : '')
            . '</td></tr></table></td></tr></table>';

        // ── points + top activities (small bars) ──
        $acts = array_values(array_filter($ctx['by_activity'] ?? [], fn ($x) => $x[2] > 0));
        usort($acts, fn ($x, $y) => $y[2] <=> $x[2]);
        $acts = array_slice($acts, 0, 4);
        $max = max(1, $acts[0][2] ?? 1);
        $bars = '';
        foreach ($acts as [$ai, $al, $ap]) {
            $w = max(4, (int) round($ap / $max * 100));
            $bars .= '<tr><td style="padding:5px 0;' . $F . 'font-size:13px;color:#334155;" width="44%">' . $ai . '&nbsp; ' . $e($al) . '</td>'
                . '<td style="padding:5px 8px;" valign="middle"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
                . '<td width="' . $w . '%" height="10" bgcolor="#22B1B3" style="background:#22B1B3;border-radius:5px;font-size:0;line-height:0;height:10px;">&nbsp;</td>'
                . ($w < 100 ? '<td width="' . (100 - $w) . '%" height="10" style="font-size:0;line-height:0;">&nbsp;</td>' : '')
                . '</tr></table></td>'
                . '<td align="right" style="padding:5px 0;' . $F . 'font-size:13px;font-weight:800;color:#0F172A;white-space:nowrap;" width="70">' . number_format($ap) . ' pts</td></tr>';
        }
        $points = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;">'
            . '<tr><td class="kt-panel" style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:14px;padding:16px 18px;' . $F . '">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td style="' . $F . 'font-size:11px;font-weight:800;letter-spacing:1.5px;color:#64748B;text-transform:uppercase;">Points in ' . $e($month) . '</td>'
            . '<td align="right" style="' . $F . 'font-size:24px;font-weight:900;color:#0B2545;">⭐ ' . number_format((int) $ctx['points']) . '</td></tr></table>'
            . ($bars ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:8px;">' . $bars . '</table>' : '')
            . '</td></tr></table>';

        // ── from the awarder ──
        $from = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;">'
            . '<tr><td class="kt-callout" style="background:#ECFDF5;--kt-edge:#10B981;border-radius:10px;padding:14px 16px;' . $F . 'font-size:14px;line-height:1.55;color:#065F46;">'
            . '<strong style="color:#065F46;">From ' . $e($ctx['by']) . '</strong>'
            . (trim((string) ($ctx['note'] ?? '')) !== ''
                ? '<div style="margin-top:6px;font-size:15px;font-style:italic;color:#064E3B;">“' . nl2br($e(trim((string) $ctx['note']))) . '”</div>'
                : '<div style="margin-top:4px;color:#065F46;">Thank you for the care and the detail you put into every day.</div>')
            . '</td></tr></table>';

        // ── the button (bulletproof: a table cell carries the colour for Outlook) ──
        $btn = '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 8px;"><tr>'
            . '<td class="kt-btn" align="center" bgcolor="#13708A" style="background:#13708A;border-radius:12px;">'
            . '<a class="kt-btn" href="' . self::APP . '" style="display:inline-block;padding:15px 34px;' . $F . 'font-size:16px;font-weight:800;color:#FFFFFF;text-decoration:none;border-radius:12px;">⭐ Open My points</a>'
            . '</td></tr></table>'
            . '<div style="text-align:center;' . $F . 'font-size:12.5px;color:#64748B;margin:0 0 6px;">'
            . ($isCard ? 'For your security, your gift card is not in this email. It is waiting in KiddieTrac under My points.'
                       : 'See your points, what earned them and your centre’s leaderboard any time.')
            . '</div>';

        $body = $hero . $reward . $points . $from . $btn
            . '<div style="' . $F . 'font-size:13px;color:#64748B;line-height:1.6;margin-top:18px;text-align:center;">Keep it up! Every observation, daily log, walk and photo you share earns points toward next month.</div>';

        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow' => 'EDUCATOR REWARDS',
            'title' => 'A thank-you for your hard work',
            'subtitle' => $month . ' · ' . number_format((int) $ctx['points']) . ' points',
            'preheader' => $headline . ' for your ' . $month . ' points, from ' . $ctx['by'] . '.',
        ]);

        return ['⭐ You’ve earned a reward: ' . $headline, $html];
    }

    /** The context for a real award. */
    public static function context(int $agencyId, object $a, $by): array
    {
        $u = DB::table('users')->where('id', $a->user_id)->first(['first_name', 'last_name']);
        $earners = EducatorPoints::earners($agencyId);
        $by_activity = [];
        if (isset($earners[(int) $a->user_id])) {
            $r = EducatorPoints::compute($agencyId, $a->month, [(int) $a->user_id => $earners[(int) $a->user_id]])[(int) $a->user_id];
            foreach (EducatorPoints::ACTIVITIES as $k => $def) {
                $by_activity[] = [$def[1], $def[0], (int) ($r['by'][$k]['points'] ?? 0)];
            }
        }

        return [
            'first' => trim((string) ($u->first_name ?? '')) ?: 'there',
            'by' => trim(($by->first_name ?? '') . ' ' . ($by->last_name ?? '')) ?: 'Your director',
            'month' => $a->month,
            'points' => (int) $a->points,
            'by_activity' => $by_activity,
            'note' => $a->note,
        ];
    }

    /** Queue the real email: To the educator, BCC the managers. Returns who it went to. */
    public static function send(int $agencyId, object $a, $by): array
    {
        $rc = self::recipients($agencyId, (int) $a->user_id);
        [$subject, $html] = self::build($agencyId, $a, self::context($agencyId, $a, $by));
        $to = $rc['to'];
        $bcc = $rc['bcc'];
        if (! $to && $bcc) {
            // The educator cannot be emailed: the managers still get their copy, addressed
            // to the first of them with the rest on BCC.
            $to = array_shift($bcc);
            $rc['skipped'][] = 'sent to ' . $to['email'] . ' instead';
        }
        if (! $to) {
            return $rc + ['sent' => false];
        }
        $toE = $to['email'];
        $bccE = array_column($bcc, 'email');
        dispatch(function () use ($agencyId, $toE, $bccE, $subject, $html) {
            \App\Services\AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($toE, $bccE, $subject) {
                $m->to($toE)->from('noreply@kiddietrac.com', 'KiddieTrac')
                  ->replyTo('support@kiddietrac.com', 'Kiddietrac Support')->subject($subject);
                if ($bccE) {
                    $m->bcc($bccE);
                }
            });
        })->onQueue('mail');

        return $rc + ['sent' => true, 'to_email' => $toE, 'bcc_emails' => $bccE];
    }

    /**
     * Render the exact same email with sample data and send it ONLY to $to, no BCC, subject
     * "[TEST] …", synchronously so the result is known. Nothing is written.
     */
    public static function sendTest(int $agencyId, string $to): array
    {
        $a = (object) ['user_id' => 0, 'month' => Carbon::now()->subMonth()->format('Y-m'), 'points' => 1410,
            'amount' => 25, 'method' => 'gift_card_provider', 'brand' => 'Amazon.ca', 'note' => 'Your observations this month were wonderful. The families loved the photos from the garden walk. Thank you, Emily!'];
        $ctx = ['first' => 'Emily', 'by' => 'Rebecca Bright', 'month' => $a->month, 'points' => 1410, 'note' => $a->note,
            'by_activity' => [['✅', 'Daily logs', 900], ['👀', 'Observations', 360], ['⚠️', 'Incident reports', 75], ['📸', 'Photos & videos shared', 45], ['🚶', 'Walks & outings', 30]]];
        [$subject, $html] = self::build($agencyId, $a, $ctx);
        $sent = \App\Services\AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $subject) {
            $m->to($to)->from('noreply@kiddietrac.com', 'KiddieTrac')
              ->replyTo('support@kiddietrac.com', 'Kiddietrac Support')->subject('[TEST] ' . $subject);
        });

        return ['subject' => '[TEST] ' . $subject, 'message_id' => $sent ? $sent->getMessageId() : null];
    }
}
