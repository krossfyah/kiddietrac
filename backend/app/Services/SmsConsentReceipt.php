<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Controllers\Api\SmsConsentController;
use Illuminate\Support\Facades\DB;

/**
 * A WRITTEN RECORD OF WHAT THEY CHOSE (2026-09-18).
 *
 * Anthony: "there should be a confirmation email sent out once you opt'd in or do not
 * opt'd in for record keeping."
 *
 * Consent was being recorded in the database and nowhere the person could see. The audit
 * log proves it to US; it proves nothing to THEM, and a consent record that only one side
 * holds is the weaker half of the pair a carrier asks for. So both answers now produce an
 * email: a yes and a no are equally worth being able to point at later.
 *
 * ONE PLACE, FIVE DOORS. Consent can change through the app toggle, a STOP/START/YES text
 * from the handset, the emailed consent link, a director recording it, and onboarding.
 * Five copies of "and also send an email" is how one of them ends up not sending one, so
 * every path calls this. Two of those doors are the optIn()/optOut() helpers in
 * SmsConsentController, which is why hooking those covers the app and the handset at once.
 *
 * WHAT THE EMAIL HAS TO CONTAIN to be worth anything as a record: what they chose, when
 * (in the agency's timezone, never UTC), which number it applies to, how the answer was
 * given, the EXACT wording they agreed to, and how to change their mind. A message that
 * only says "thanks, saved" proves nothing.
 */
final class SmsConsentReceipt
{
    /**
     * @param  string  $source  app | sms | email_link | admin | onboarding
     * @param  string|null  $recordedBy  the director's name, when they recorded it on
     *                                   someone's behalf - the one case where the person
     *                                   did not press the button themselves
     * @param  int|null  $agencyId  the agency this consent was given TO, when the caller
     *                              knows it. It usually does, and its answer is better
     *                              than any guess made here - see agencyOf().
     */
    public static function send(int $userId, bool $optedIn, string $source, ?string $recordedBy = null, ?int $agencyId = null): bool
    {
        $u = DB::table('users')->where('id', $userId)
            ->first(['id', 'first_name', 'last_name', 'email', 'phone', 'sms_consent_text']);

        /* No address, no receipt. Never invent one, and never fall back to an agency
           mailbox: this is the person's own record of their own decision. */
        if (! $u || trim((string) ($u->email ?? '')) === '') {
            return false;
        }

        /* THE SAME RECORD, SOMEWHERE THEY CAN GO AND LOOK (2026-09-24).

           Anthony: "ensure that parents see's this in their documents."

           The email above is posted once and then lives in whatever inbox it landed
           in. A parent who deleted it, or changed address, or simply cannot find it
           has no way back to what they agreed to - and neither has the office when
           somebody asks. Filing it against the account fixes both: /auth/me/documents
           reads scope_type 'user', so it appears under My documents for them and on
           their record for staff.

           Here rather than in optIn(), because this service is already the ONE place
           every one of the five doors passes through. Only for a yes: a decline is
           worth an email, but filing "here is the agreement you did not make" as a
           document on somebody's record would be a strange thing to produce. */
        if ($optedIn) {
            \App\Support\SmsConsentRecord::file(
                $userId,
                SmsConsentController::CONSENT_TEXT,
                SmsConsentController::CONSENT_VERSION,
                $source,
                $u->phone ?? null,
                null,
                null
            );
        }

        $agencyId = $agencyId ?: self::agencyOf($userId);
        $agencyName = $agencyId
            ? (DB::table('agencies')->where('id', $agencyId)->value('name') ?: 'your centre')
            : 'your centre';

        /* THE CONFIRMATION TEXT THE POLICY PROMISES (2026-09-24).

           Anthony: "did we send a text that highlights the opt'd in through
           kiddietrac/ilearn? and log that somewhere that was done?"

           No. Eight people are opted in; two had a confirmation attempted and BOTH were
           skipped, and the other six never had one attempted at all. Two faults:

             - the only doors that tried were the app toggle and an inbound START; the
               emailed link, a director recording it and onboarding never did;
             - the app toggle passed `$u->agency_id` as the agency, and `users` has no
               such column, so it was 0 - credentials for agency zero do not exist, and
               the send was skipped as "twilio not configured".

           It belongs here for the same reason the email and the filed copy do: this is
           the one place every door passes through, and $agencyId above is resolved from
           role_assignments rather than from a column that does not exist. A carrier
           expects this message, and the consent wording promises the STOP and HELP
           instructions it carries.

           NOT for an inbound START: that handset already got MSG_CONFIRM as the direct
           reply to its own text, and a second copy a moment later is the sender looking
           broken. Not for a decline either - there is nothing to confirm.

           Failure is logged and swallowed: a confirmation that does not go is worth
           knowing about, but it is not worth failing the opt-in the person asked for. */
        if ($optedIn && $source !== 'sms' && trim((string) ($u->phone ?? '')) !== '') {
            try {
                app(\App\Http\Controllers\Api\SmsController::class)->sendOne(
                    (int) $agencyId,
                    $userId,
                    (string) $u->phone,
                    sprintf(SmsConsentController::MSG_CONFIRM, $agencyName),
                    'consent_confirm'
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('SMS consent confirmation failed', [
                    'user' => $userId, 'e' => $e->getMessage(),
                ]);
            }
        }

        /* The agency's clock, not the server's. A parent in Toronto reading "02:14" for
           something they did at 22:14 has been handed a record that contradicts them. */
        $tz = ($agencyId ? DB::table('agencies')->where('id', $agencyId)->value('timezone') : null)
            ?: (config('app.display_timezone') ?: 'America/Toronto');
        $when = now()->setTimezone($tz)->format('D j M Y, g:ia T');

        $how = [
            'app' => 'in the KiddieTrac app',
            'sms' => 'by replying to a text message',
            'email_link' => 'from the link we emailed you',
            'admin' => 'recorded by ' . ($recordedBy ?: 'a member of staff') . ' after you told them',
            'onboarding' => 'while setting up your account',
        ][$source] ?? 'in KiddieTrac';

        $phone = trim((string) ($u->phone ?? ''));
        $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));

        $rows = [
            ['Your answer', $optedIn ? 'Yes - send me text alerts' : 'No - do not send me text alerts'],
            ['When', $when],
            ['How', $how],
        ];
        if ($phone !== '') {
            $rows[] = [$optedIn ? 'Number we will text' : 'Number on file', e($phone)];
        }

        $body = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . '<tr><td style="font-size:15px;color:#334155;line-height:1.65;">'
            . 'Hello' . ($name ? ' ' . e(explode(' ', $name)[0]) : '') . ',<br><br>'
            . 'This confirms your choice about text messages from ' . e($agencyName)
            . '. Keep this email for your records.'
            . '</td></tr>'
            . '<tr><td style="padding:20px 0 0;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            . 'style="border:1px solid #E2E8F0;border-radius:10px;overflow:hidden;">'
            . implode('', array_map(fn ($r) =>
                '<tr>'
                . '<td style="padding:11px 14px;background:#F8FAFC;font-size:12.5px;color:#64748B;'
                . 'font-weight:700;width:38%;border-bottom:1px solid #E2E8F0;">' . e($r[0]) . '</td>'
                . '<td style="padding:11px 14px;font-size:13.5px;color:#0F172A;'
                . 'border-bottom:1px solid #E2E8F0;">' . $r[1] . '</td></tr>', $rows))
            . '</table></td></tr>';

        if ($optedIn) {
            /* The exact words, quoted back. "You agreed to our terms" is an assertion;
               this is the record. Falls back to the current wording only when the stored
               copy is missing, which should not happen but must not produce a blank. */
            $agreed = (string) ($u->sms_consent_text ?? '');
            $agreed = $agreed !== '' ? preg_replace('/^\S+\s::\s/', '', $agreed) : SmsConsentController::CONSENT_TEXT;
            $body .= '<tr><td style="padding:18px 0 0;font-size:12.5px;color:#64748B;line-height:1.6;">'
                . '<strong style="color:#475569;">What you agreed to:</strong><br>'
                . e($agreed)
                . '</td></tr>'
                . '<tr><td style="padding:14px 0 0;font-size:12.5px;color:#94A3B8;line-height:1.6;">'
                . 'To stop the messages at any time, reply <strong>STOP</strong> to any text, or turn '
                . 'text alerts off in Settings. Reply <strong>HELP</strong> for help.'
                . '</td></tr>';
        } else {
            $body .= '<tr><td style="padding:18px 0 0;font-size:12.5px;color:#64748B;line-height:1.6;">'
                . 'You will not receive text messages from ' . e($agencyName) . '. Email and in-app '
                . 'notices are unchanged, so you will still hear about your child as usual.<br><br>'
                . 'If this was not you, or you change your mind, contact ' . e($agencyName) . '.'
                . '</td></tr>';
        }

        $body .= '</table>';

        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow' => 'TEXT ALERTS',
            'title' => $optedIn ? 'Text alerts are on' : 'Text alerts are off',
            'subtitle' => 'A record of your choice',
            'preheader' => $optedIn
                ? 'You said yes to text alerts from ' . $agencyName . '.'
                : 'You said no to text alerts from ' . $agencyName . '.',
        ]);

        try {
            AgencyMailer::forAgency($agencyId ?: 0)->html($html, function ($m) use ($u, $name, $optedIn, $agencyId) {
                $m->to($u->email, $name ?: null)
                    ->subject($optedIn ? 'Text alerts are on' : 'Text alerts are off');
                try {
                    $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $agencyId);
                } catch (\Throwable $e) {
                }
                /* A consent receipt is transactional: it is the answer to something the
                   person just did, and it is the half of the record THEY hold. It has to
                   reach an account that has not been claimed yet - the not-onboarded gate
                   would otherwise eat exactly the confirmations sent to new parents, who
                   are the people this whole consent drive is aimed at. */
                try {
                    $m->getHeaders()->addTextHeader('X-KT-Form-Package', '1');
                } catch (\Throwable $e) {
                }
            });

            return true;
        } catch (\Throwable $e) {
            /* Never let a receipt failure undo the consent itself: the answer is recorded
               and the email is evidence of it, not the thing. The send is logged in
               email_logs either way, so a missing receipt is visible there. */
            return false;
        }
    }

    /**
     * A LAST RESORT, AND IT GUESSES.
     *
     * One person can hold roles in more than one agency - the same address can, too. My
     * first version asked role_assignments with guardian first, and on the platform owner
     * that resolved to the TEST agency because his guardian row happens to live there,
     * while his real work is at iLearn. A receipt branded with the wrong agency is bad
     * enough; the mail gate is agency-scoped, so the wrong answer can also get the message
     * suppressed by an agency the person was not writing to.
     *
     * So callers pass the agency they already know, and this only runs when none was
     * given. Even then it prefers the account's OWN default before falling back to a role
     * row, because that is the agency the person works in rather than the first one a
     * join happened to return.
     */
    private static function agencyOf(int $userId): ?int
    {
        /* THE AGENCY THEY WORK IN, and there is no column for it.

           My first attempt read users.default_agency_id / users.agency_id. NEITHER
           EXISTS - they are computed fields on the /auth/me response, not columns - so
           that version threw "Unknown column 'default_agency_id'" the moment it ran
           without an explicit agency. Caught on 21 Sep before anybody hit it; the lesson
           is that a field in an API payload is not evidence of a column.

           So it is role_assignments, ordered properly. The ORIGINAL bug was
           `orderByRaw("role = 'guardian' DESC")`, which sorts guardian FIRST - that is
           how the platform owner resolved to the Test agency, where his guardian row
           lives, rather than iLearn where he works. Guardian is now the LAST resort, and
           rows with no agency at all (platform_admin) are skipped. */
        $id = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)
            ->whereNotNull('agency_id')
            ->orderByRaw("role = 'guardian' ASC")
            ->value('agency_id');

        return $id ? (int) $id : null;
    }
}
