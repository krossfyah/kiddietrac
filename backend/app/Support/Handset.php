<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * One phone, several accounts (2026-09-29).
 *
 * Anthony: "if phone numbers are shared amongst many accounts wire it up where it
 * sends based on the account/user name that opt'd".
 *
 * Consent is a column on the user row, but the thing a carrier regulates, and the
 * thing a person holds, is the HANDSET. Measured on live data: 3 of 82 numbers are
 * shared. One iLearn parent holds an admin, an educator and a guardian account on the
 * same phone and opted in only as the guardian. So every staff text to the other two
 * accounts was skipped "no sms consent", although the person on that phone had said
 * yes to texts from that agency. The consent wording is agency-level ("...plus
 * urgent notices from your agency"), not per login.
 *
 * The rules, used by SMS (SmsController::sendOne) and voice (VoiceController::callOne):
 *
 *  1. CONSENT FOLLOWS THE HANDSET, WITHIN ONE AGENCY. A send to an account may rely
 *     on an opt-in by ANOTHER live account on the same number that holds an active
 *     role in the SAME agency. An opt-in given to a different agency never counts.
 *     The account whose consent was used is recorded on the row (consent_user_id).
 *  2. THE MOST RECENT DECISION WINS. If any of those accounts opted out after the
 *     opt-in being relied on, nothing is sent. (STOP from the handset already opts
 *     out every account on it: SmsConsentController::usersByPhone.)
 *  3. ONE HANDSET, ONE COPY. The same words to the same number from the same agency
 *     within ten minutes are skipped as a duplicate, so a person with three accounts
 *     gets an announcement once, not three times.
 *  4. A "DO NOT RING ME" BELONGS TO THE HANDSET. voice_opt_out on any live account on
 *     the number stops calls to all of them.
 */
final class Handset
{
    public const DUPLICATE_MINUTES = 10;

    public static function last10(?string $phone): string
    {
        $d = preg_replace('/\D/', '', (string) $phone) ?? '';

        return strlen($d) >= 10 ? substr($d, -10) : '';
    }

    /** Every live account whose phone is this number. */
    public static function accounts(?string $phone)
    {
        $k = self::last10($phone);
        if ($k === '') {
            return collect();
        }

        return DB::table('users')
            ->whereRaw("RIGHT(REGEXP_REPLACE(COALESCE(phone,''), '[^0-9]', ''), 10) = ?", [$k])
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->get(['id', 'first_name', 'last_name', 'sms_opt_in', 'sms_opt_in_at', 'sms_opt_out_at', 'voice_opt_out']);
    }

    /**
     * May this agency text this person on this number, and on whose consent?
     *
     * @return array{ok:bool, via:?int, reason:string}
     */
    public static function smsConsent(int $agencyId, int $userId, ?string $phone): array
    {
        $target = DB::table('users')->where('id', $userId)
            ->first(['id', 'sms_opt_in', 'sms_opt_in_at', 'sms_opt_out_at']);

        $all = self::accounts($phone);
        // The target plus the other accounts on this number that belong to this agency.
        $inAgency = $all->isEmpty() ? [] : DB::table('role_assignments')
            ->where('agency_id', $agencyId)->where('active', true)
            ->whereIn('user_id', $all->pluck('id')->all())
            ->pluck('user_id')->map(fn ($i) => (int) $i)->unique()->all();
        $pool = $all->filter(fn ($a) => (int) $a->id === $userId || in_array((int) $a->id, $inAgency, true));
        if ($target && ! $pool->contains(fn ($a) => (int) $a->id === $userId)) {
            $pool->push((object) ['id' => $target->id, 'sms_opt_in' => $target->sms_opt_in,
                'sms_opt_in_at' => $target->sms_opt_in_at, 'sms_opt_out_at' => $target->sms_opt_out_at]);
        }

        // The opt-in to rely on: the target's own if it has one, else the latest in the pool.
        $consenting = $pool->filter(fn ($a) => (int) ($a->sms_opt_in ?? 0) === 1);
        if ($consenting->isEmpty()) {
            return ['ok' => false, 'via' => null, 'reason' => 'no sms consent'];
        }
        $via = $consenting->first(fn ($a) => (int) $a->id === $userId)
            ?? $consenting->sortByDesc(fn ($a) => (string) ($a->sms_opt_in_at ?? ''))->first();

        // A later "no" from the same person, on any of their accounts in this agency, wins.
        $viaAt = (string) ($via->sms_opt_in_at ?? '');
        $laterNo = $pool->first(fn ($a) => ! empty($a->sms_opt_out_at)
            && ($viaAt === '' || (string) $a->sms_opt_out_at > $viaAt));
        if ($laterNo) {
            return ['ok' => false, 'via' => null,
                'reason' => 'opted out on account #' . $laterNo->id . ' (same phone) after opting in'];
        }

        return ['ok' => true, 'via' => (int) $via->id, 'reason' => ''];
    }

    /**
     * May an ANNOUNCEMENT be texted to this person? (2026-10-21)
     *
     * Anthony: "announcements when using SMS should allow text to phone numbers not opt'd
     * in for all users that the SMS are intended for (this needs to work this way)", and on
     * being asked who exactly: everyone except STOP for STAFF, and for parents everyone
     * except people who said no.
     *
     *   - STOP (an opt-out recorded from a text reply, source 'sms', on any account on this
     *     handset) always wins, for everyone. It is the carrier-mandated keyword; the
     *     carrier blocks the number itself anyway, and texting past it is what gets a
     *     sending number taken away.
     *   - Staff of this agency (a work notice to their own team): sent, even if they switched
     *     texts off in the app or declined the invitation.
     *   - Everyone else (parents): sent when they consented OR simply never answered; not
     *     sent when they said no (in the app, by the consent email, or recorded by an admin).
     *
     * @return array{ok:bool, mode:string, reason:string}  mode: consent|staff|not_answered
     */
    public static function announcementSms(int $agencyId, int $userId, ?string $phone): array
    {
        $pool = self::accounts($phone)->pluck('id')->map(fn ($i) => (int) $i)->push($userId)->unique()->values()->all();
        $stopped = DB::table('users')->whereIn('id', $pool)
            ->where('sms_consent_source', 'sms')->whereNotNull('sms_opt_out_at')->where('sms_opt_in', 0)
            ->exists();
        if ($stopped) {
            return ['ok' => false, 'mode' => 'stop', 'reason' => 'replied STOP'];
        }

        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        $isStaff = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)
            ->whereIn('role', ['educator', 'home_visitor', 'centre_director', 'agency_admin'])
            ->where(fn ($q) => $q->where('agency_id', $agencyId)->orWhereIn('centre_id', $centreIds ?: [0]))
            ->exists();
        if ($isStaff) {
            return ['ok' => true, 'mode' => 'staff', 'reason' => ''];
        }

        $consent = self::smsConsent($agencyId, $userId, $phone);
        if ($consent['ok']) {
            return ['ok' => true, 'mode' => 'consent', 'reason' => ''];
        }
        // Not consented: a "no" on any of their accounts here stops it; silence does not.
        $saidNo = DB::table('users')->whereIn('id', $pool)->whereNotNull('sms_opt_out_at')->exists();
        if ($saidNo) {
            return ['ok' => false, 'mode' => 'declined', 'reason' => 'said no to texts'];
        }

        return ['ok' => true, 'mode' => 'not_answered', 'reason' => ''];
    }

    /** Has anybody on this number asked not to be telephoned? Returns their id or null. */
    public static function voiceOptedOut(?string $phone): ?int
    {
        $a = self::accounts($phone)->first(fn ($x) => (int) ($x->voice_opt_out ?? 0) === 1);

        return $a ? (int) $a->id : null;
    }

    /**
     * The same words already sent to this number from this agency, just now?
     * Returns the user id it went to (0 if none recorded), or null when it is not a duplicate.
     */
    public static function duplicateOf(string $table, int $agencyId, ?string $phone, string $body): ?int
    {
        $k = self::last10($phone);
        if ($k === '' || $body === '') {
            return null;
        }
        $row = DB::table($table)
            ->where('agency_id', $agencyId)
            ->where('body', $body)
            ->where('created_at', '>=', now()->subMinutes(self::DUPLICATE_MINUTES))
            ->whereNotIn('status', ['skipped', 'failed'])
            ->whereRaw("RIGHT(REGEXP_REPLACE(COALESCE(to_phone,''), '[^0-9]', ''), 10) = ?", [$k])
            ->when($table === 'sms_messages', fn ($q) => $q->where('direction', 'out'))
            ->orderByDesc('id')
            ->first(['id', 'to_user_id']);

        return $row ? (int) ($row->to_user_id ?? 0) : null;
    }
}
