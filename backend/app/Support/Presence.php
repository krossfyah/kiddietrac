<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Staff presence (2026-08-07) — a green/amber "available vs idle" signal for the
 * team-on-floor widget + provider daily overview. Uses the same activity signal
 * the floor-staff widget already relies on: personal_access_tokens.last_used_at
 * (bumped on every API call), so "available" = actively using the app right now,
 * "idle" = present/clocked in but hasn't touched the app in a few minutes.
 */
final class Presence
{
    /** Minutes of API inactivity after which a user reads as "idle". */
    public const AVAILABLE_WITHIN_MIN = 5;

    /**
     * @param  int[] $userIds
     * @return array<int,string> user_id => 'available' | 'idle'
     */
    public static function forUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (! $userIds) return [];

        $last = [];
        foreach (DB::table('personal_access_tokens')
            ->whereIn('tokenable_id', $userIds)
            ->whereNotNull('last_used_at')
            ->select('tokenable_id', DB::raw('MAX(last_used_at) as t'))
            ->groupBy('tokenable_id')->get() as $r) {
            $last[(int) $r->tokenable_id] = $r->t;
        }

        $cutoff = Carbon::now()->subMinutes(self::AVAILABLE_WITHIN_MIN);
        $out = [];
        foreach ($userIds as $uid) {
            $t = $last[$uid] ?? null;
            $out[$uid] = ($t && Carbon::parse($t)->gt($cutoff)) ? 'available' : 'idle';
        }
        return $out;
    }
}
