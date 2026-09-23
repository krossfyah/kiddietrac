<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\PasswordPolicy;
use Illuminate\Support\Facades\DB;

/**
 * "YOUR PASSWORD NEEDS CHANGING" (2026-09-21).
 *
 * Anthony: "emails should be sent out indicating password expiry/rotation to occur with
 * each user."
 *
 * Written to be acted on, not filed. What that means in practice:
 *
 *   - it says WHEN, as a date and a number of days, not "soon";
 *   - it has ONE button, going to the change-password screen;
 *   - it says what happens if they do nothing, because that is the question a person
 *     actually has and a vague warning just makes them anxious;
 *   - it never contains the password, a reset token, or a one-click link that sets one.
 *     A password email that can itself be used to take the account over is worth more to
 *     an attacker than the password. This one carries no capability at all - it points at
 *     a screen that asks them to sign in.
 *
 * The last point matters more than usual this week: the account it is warning about may
 * be the one somebody spent eighteen minutes guessing at on 20 September.
 */
final class PasswordExpiryMail
{
    /**
     * @param  int  $daysLeft  negative when already overdue
     * @return bool  whether the mail was actually delivered (not merely handed over)
     */
    public static function send(int $userId, int $daysLeft): bool
    {
        $u = DB::table('users')->where('id', $userId)
            ->first(['id', 'first_name', 'last_name', 'email', 'password_changed_at']);
        if (! $u || trim((string) ($u->email ?? '')) === '') {
            return false;
        }

        /* The account's OWN agency first. An unordered role_assignments lookup returns
           whichever row the join happens to yield, and for somebody who holds roles in
           two agencies that is a coin toss - it picked the TEST agency for the platform
           owner, which is on the mail kill-switch, so his mail would have been silently
           suppressed. Same trap as SmsConsentReceipt::agencyOf(). */
        /* role_assignments, guardian LAST and NULL agencies skipped. users has no
           agency column - default_agency_id and agency_id are computed on the /auth/me
           payload, not stored - and an unordered lookup picks whichever row the join
           yields, which for somebody in two agencies is a coin toss. */
        $agencyId = (int) (DB::table('role_assignments')->where('user_id', $userId)
            ->where('active', true)->whereNotNull('agency_id')
            ->orderByRaw("role = 'guardian' ASC")
            ->value('agency_id') ?: 0);
        $agencyName = $agencyId
            ? (DB::table('agencies')->where('id', $agencyId)->value('name') ?: 'KiddieTrac')
            : 'KiddieTrac';

        $tz = ($agencyId ? DB::table('agencies')->where('id', $agencyId)->value('timezone') : null)
            ?: (config('app.display_timezone') ?: 'America/Toronto');

        $due = \Illuminate\Support\Carbon::parse($u->password_changed_at)
            ->addDays(PasswordPolicy::maxAgeDays())->setTimezone($tz);

        $overdue = $daysLeft < 0;
        $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
        $first = $name ? explode(' ', $name)[0] : '';

        $when = $overdue
            ? 'It expired on ' . $due->format('l j F') . '.'
            : ($daysLeft === 0
                ? 'It expires <strong>today</strong>.'
                : ($daysLeft === 1
                    ? 'It expires <strong>tomorrow</strong>, ' . $due->format('l j F') . '.'
                    : 'It expires in <strong>' . $daysLeft . ' days</strong>, on '
                        . $due->format('l j F') . '.'));

        $consequence = $overdue
            ? 'You will be asked to set a new one the next time you sign in. Nothing else about '
                . 'your account has changed, and none of your work is affected.'
            : 'If you do nothing, you will simply be asked to set a new one when you next sign in '
                . 'after that date. Nothing is lost and no access is removed.';

        $link = rtrim((string) (config('app.portal_url') ?: 'https://app.kiddietrac.com'), '/')
            . '/dashboard.html#account';

        $body = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . '<tr><td style="font-size:15px;color:#334155;line-height:1.65;">'
            . 'Hello' . ($first ? ' ' . e($first) : '') . ',<br><br>'
            . 'As part of ' . e($agencyName) . '\'s security policy, KiddieTrac passwords are '
            . 'changed every ' . PasswordPolicy::maxAgeDays() . ' days. ' . $when
            . '</td></tr>'
            . '<tr><td style="padding:22px 0 0;"><a href="' . e($link) . '" style="background:#1F6080;'
            . 'color:#fff;text-decoration:none;font-weight:700;font-size:15px;padding:13px 26px;'
            . 'border-radius:10px;display:inline-block;">Change your password</a></td></tr>'
            . '<tr><td style="padding:20px 0 0;font-size:13.5px;color:#475569;line-height:1.6;">'
            . $consequence
            . '</td></tr>'
            /* No token, no link that sets a password, and we say so. Somebody who has just
               been told their password is expiring is exactly the person a phishing email
               will target next, so the honest note is worth the space. */
            . '<tr><td style="padding:18px 0 0;font-size:12.5px;color:#94A3B8;line-height:1.6;">'
            . 'We will never email you a password or a link that sets one. The button above '
            . 'opens KiddieTrac and asks you to sign in as usual. If an email about your '
            . 'password asks for anything else, it did not come from us &mdash; forward it to '
            . '<a href="mailto:info@kiddietrac.com" style="color:#1F6080;text-decoration:none;">info@kiddietrac.com</a>.'
            . '</td></tr></table>';

        $subject = $overdue
            ? 'Your KiddieTrac password has expired'
            : ($daysLeft <= 1 ? 'Your KiddieTrac password expires ' . ($daysLeft === 0 ? 'today' : 'tomorrow')
                              : 'Your KiddieTrac password expires in ' . $daysLeft . ' days');

        $html = EmailTemplate::wrap($agencyId ?: null, $body, [
            'eyebrow' => 'ACCOUNT SECURITY',
            'title' => $overdue ? 'Your password has expired' : 'Time to change your password',
            'subtitle' => $overdue ? 'You will be asked to set a new one at your next sign-in'
                                   : 'It expires ' . $due->format('j F'),
            'preheader' => $subject,
        ]);

        $before = (int) DB::table('email_logs')->max('id');

        try {
            AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($u, $name, $subject, $agencyId) {
                $m->to($u->email, $name ?: null)->subject($subject);
                try {
                    $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $agencyId);
                } catch (\Throwable $e) {
                }
            });
        } catch (\Throwable $e) {
            return false;
        }

        /* "Sent" has to mean delivered. The mailer returning without throwing only means
           the message was handed over; the suppression listener writes its row during the
           send, so the log is the answer. */
        $log = DB::table('email_logs')->where('id', '>', $before)
            ->where('to_email', 'like', '%' . $u->email . '%')
            ->orderByDesc('id')->first(['status']);

        return ! $log || $log->status !== 'suppressed';
    }
}
