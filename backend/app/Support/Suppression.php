<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Do not contact this agency" — ALL channels (2026-07-14).
 *
 * The email kill-switch (SuppressAgencyMail) only stopped email. On 2026-07-14 the
 * check-in reminder ran against every agency and reached 39 real iLearn parents by
 * email, 38 by in-app notification, and 16 of them by phone push — because push,
 * SMS and in-app notifications had no guard at all.
 *
 * So the rule now lives in ONE place and every outbound channel asks it:
 *
 *   • email — SuppressAgencyMail (MessageSending event)
 *   • push  — FcmService::sendToUser
 *   • SMS   — SmsController::sendOne
 *   • the scheduled jobs (reminders, daily summaries) skip suppressed agencies
 *     outright, so no in-app notification row is written either
 *
 * Configured by MAIL_SUPPRESS_AGENCIES in .env (kept as one setting so the switch
 * can't be half-flipped). Empty it to go live.
 */
final class Suppression
{
    private const CACHE_TTL = 120;

    /** @return int[] */
    public static function agencyIds(): array
    {
        $raw = (string) env('MAIL_SUPPRESS_AGENCIES', '');
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($v) => (int) trim($v),
            explode(',', $raw)
        )));
    }

    public static function enabled(): bool
    {
        return self::agencyIds() !== [];
    }

    public static function isAgency(?int $agencyId): bool
    {
        if (! $agencyId || ! self::enabled()) {
            return false;
        }

        return in_array((int) $agencyId, self::agencyIds(), true);
    }

    /**
     * Is this user reachable at a suppressed agency? Covers staff (role
     * assignments) and guardians (through their family's centre).
     */
    public static function isUser(?int $userId): bool
    {
        if (! $userId || ! self::enabled()) {
            return false;
        }

        return in_array((int) $userId, self::userIds(), true);
    }

    /** Log what we stopped — silence with no trail is its own hazard. */
    public static function note(string $channel, int $userId, string $what): void
    {
        Log::warning('Suppressed ' . $channel . ' to a live-agency user', [
            'user_id' => $userId,
            'detail' => $what,
        ]);
    }

    /** @return int[] */
    private static function userIds(): array
    {
        $ids = self::agencyIds();
        sort($ids);

        return Cache::remember('kt.suppressed_user_ids:' . implode(',', $ids), self::CACHE_TTL, function () use ($ids) {
            $centreIds = DB::table('centres')->whereIn('agency_id', $ids)->pluck('id');

            $staff = DB::table('role_assignments')
                ->where(function ($q) use ($ids, $centreIds) {
                    $q->whereIn('agency_id', $ids)->orWhereIn('centre_id', $centreIds);
                })
                ->pluck('user_id');

            $guardians = DB::table('guardians as g')
                ->join('families as f', 'f.id', '=', 'g.family_id')
                ->whereIn('f.centre_id', $centreIds)
                ->pluck('g.user_id');

            return $staff->merge($guardians)
                ->map(fn ($i) => (int) $i)
                ->unique()
                ->values()
                ->all();
        });
    }
}
