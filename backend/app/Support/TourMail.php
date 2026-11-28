<?php

namespace App\Support;

use App\Services\EmailTemplate;
use Carbon\Carbon;

/**
 * TOUR EMAILS TO THE FAMILY (2026-11-28, Anthony: "fix all of the gaps" - changing a tour's status on the Tours screen
 * told the prospective family nothing).
 *
 * Pure builders, one per email (no sends, no writes): confirmed, rescheduled, cancelled and the optional thank-you
 * after a completed visit. House frame (EmailTemplate::wrap), greeting by the parent's first name, the visit in a card,
 * signed by the centre's team the way families know it (FamilyFacingName). The family has no account yet, so there is
 * no app button: they reply to the email (Reply-To = the centre's own address when it has one, see TourNotice).
 *
 * $t: a tour_bookings row joined with nothing; times in tour_at are UTC and are shown in the CENTRE's timezone.
 */
final class TourMail
{
    private static function e($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    private static function p(string $t, string $extra = ''): string
    {
        return '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;color:#1E293B;' . $extra . '">' . $t . '</p>';
    }

    /** "Hi Priya," - the first word of the name the family typed, or a plain hello. */
    public static function first(object $t): string
    {
        $f = trim(explode(' ', trim((string) ($t->parent_name ?? '')))[0] ?? '');

        return $f !== '' ? $f : 'there';
    }

    /** "Thursday, December 3 at 6:00 PM" in the centre's timezone. */
    public static function when(?string $utc, ?int $centreId): string
    {
        if (! $utc) return '';
        try {
            return Carbon::parse($utc, 'UTC')->setTimezone(AgencyTime::tzForCentre($centreId))->format('l, F j \a\t g:i A');
        } catch (\Throwable $e) {
            return (string) $utc;
        }
    }

    private static function place(object $t): array
    {
        $c = \Illuminate\Support\Facades\DB::table('centres')->where('id', (int) $t->centre_id)->first(['address_line1', 'address_line2', 'city', 'province', 'postal_code', 'phone']);
        $addr = $c ? trim(implode(', ', array_filter([$c->address_line1 ?? '', $c->address_line2 ?? '', trim(($c->city ?? '') . ' ' . ($c->province ?? '')), $c->postal_code ?? '']))) : '';

        return [FamilyFacingName::org((int) $t->centre_id, (int) $t->agency_id, 'our centre'), $addr, $c->phone ?? ''];
    }

    private static function card(array $rows): string
    {
        $h = '';
        foreach ($rows as [$k, $v, $strike]) {
            if ($v === '' || $v === null) continue;
            $h .= '<tr><td style="padding:4px 14px 4px 0;font-size:13px;color:#64748B;white-space:nowrap;vertical-align:top;">' . self::e($k) . '</td>'
                . '<td style="padding:4px 0;font-size:14px;color:#0F172A;font-weight:600;' . ($strike ? 'text-decoration:line-through;color:#94A3B8;font-weight:500;' : '') . '">' . nl2br(self::e($v)) . '</td></tr>';
        }

        return '<table role="presentation" class="kt-panel" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px;margin:0 0 16px;"><tr><td style="padding:12px 16px;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0">' . $h . '</table></td></tr></table>';
    }

    private static function signoff(string $team): string
    {
        return self::p('Warm regards,<br><strong>The ' . self::e($team) . ' team</strong>', 'margin:4px 0 0;');
    }

    private static function team(object $t): string
    {
        return FamilyFacingName::org((int) $t->centre_id, (int) $t->agency_id, '') ?: FamilyFacingName::agency((int) $t->agency_id) ?: 'KiddieTrac';
    }

    /** @return array{subject:string, html:string} */
    public static function confirmed(object $t): array
    {
        [$org, $addr, $phone] = self::place($t);
        $team = self::team($t);
        $when = self::when($t->tour_at, (int) $t->centre_id);
        $body = self::p('Hi ' . self::e(self::first($t)) . ',')
            . self::p('Your tour of <strong>' . self::e($org) . '</strong> is confirmed. We are looking forward to meeting you and showing you around.')
            . self::card([['When', $when, false], ['Where', $addr ?: $org, false], ['Phone', $phone, false]])
            . self::p('Feel free to bring your child along. If the time no longer works, just reply to this email and we will find another one.', 'font-size:14px;color:#475569;')
            . self::signoff($team);

        return ['subject' => 'Your tour is confirmed: ' . $when, 'html' => EmailTemplate::wrap((int) $t->agency_id, $body, [
            'eyebrow' => '✅ TOUR CONFIRMED', 'title' => 'See you soon', 'subtitle' => $org,
            'preheader' => 'Your tour of ' . $org . ' is confirmed for ' . $when . '.', 'support_email' => $t->reply_to ?? null,
        ])];
    }

    /** @return array{subject:string, html:string} */
    public static function rescheduled(object $t, ?string $oldUtc): array
    {
        [$org, $addr, $phone] = self::place($t);
        $team = self::team($t);
        $when = self::when($t->tour_at, (int) $t->centre_id);
        $was = self::when($oldUtc, (int) $t->centre_id);
        $body = self::p('Hi ' . self::e(self::first($t)) . ',')
            . self::p('The time of your tour of <strong>' . self::e($org) . '</strong> has changed. Here is the new time.')
            . self::card([['New time', $when, false], ['Was', $was, true], ['Where', $addr ?: $org, false], ['Phone', $phone, false]])
            . self::p('If the new time does not suit you, reply to this email and we will find one that does.', 'font-size:14px;color:#475569;')
            . self::signoff($team);

        return ['subject' => 'New time for your tour: ' . $when, 'html' => EmailTemplate::wrap((int) $t->agency_id, $body, [
            'eyebrow' => '🗓️ TOUR RESCHEDULED', 'title' => 'Your tour has a new time', 'subtitle' => $org,
            'preheader' => 'Your tour of ' . $org . ' is now on ' . $when . '.', 'support_email' => $t->reply_to ?? null,
        ])];
    }

    /** @return array{subject:string, html:string} */
    public static function cancelled(object $t): array
    {
        [$org] = self::place($t);
        $team = self::team($t);
        $when = self::when($t->tour_at, (int) $t->centre_id);
        $body = self::p('Hi ' . self::e(self::first($t)) . ',')
            . self::p('Your tour of <strong>' . self::e($org) . '</strong> on ' . self::e($when) . ' has been cancelled.')
            . self::p('We would still love to show you around. Reply to this email and we will book a time that works for you.')
            . self::signoff($team);

        return ['subject' => 'Your tour has been cancelled', 'html' => EmailTemplate::wrap((int) $t->agency_id, $body, [
            'eyebrow' => 'TOUR CANCELLED', 'title' => 'Your tour has been cancelled', 'subtitle' => $org,
            'preheader' => 'Your tour of ' . $org . ' on ' . $when . ' has been cancelled.', 'support_email' => $t->reply_to ?? null,
        ])];
    }

    /** @return array{subject:string, html:string} */
    public static function thanks(object $t): array
    {
        [$org] = self::place($t);
        $team = self::team($t);
        $body = self::p('Hi ' . self::e(self::first($t)) . ',')
            . self::p('Thank you for visiting <strong>' . self::e($org) . '</strong>. It was lovely to meet you.')
            . self::p('If you have any questions about our program, spaces or next steps, just reply to this email. We are happy to help.')
            . self::signoff($team);

        return ['subject' => 'Thank you for visiting ' . $org, 'html' => EmailTemplate::wrap((int) $t->agency_id, $body, [
            'eyebrow' => '💛 THANK YOU', 'title' => 'Thank you for visiting', 'subtitle' => $org,
            'preheader' => 'Thank you for visiting ' . $org . '.', 'support_email' => $t->reply_to ?? null,
        ])];
    }
}
