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
