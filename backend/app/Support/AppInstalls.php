<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * IS THE KIDDIETRAC APP ON THEIR PHONE? (2026-10-23, Anthony: "show that the android app
 * or ios app was downloaded and installed on users phones in their user profile settings
 * and the quick glance under agency overview").
 *
 * The app registers its phone for push when it is signed in (device_tokens, platform
 * android | ios, with the app build and the last time it was active); the browser's own
 * web push is 'web' and is not the app. So "installed" here means: the app has been signed
 * in on that phone and is still registered. A phone that uninstalled the app drops off when
 * the push service rejects its token.
 */
class AppInstalls
{
    /** uid => ['android' => ['build' => int|null, 'last' => 'Y-m-d H:i:s'|null, 'phones' => n], 'ios' => ...] */
    public static function forUsers(array $userIds): array
    {
        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        if (! $userIds) return [];
        $out = [];
        foreach (array_chunk($userIds, 1000) as $chunk) {
            $rows = DB::table('device_tokens')->whereIn('user_id', $chunk)->whereIn('platform', ['android', 'ios'])
                ->select('user_id', 'platform', DB::raw('MAX(app_build) build'), DB::raw('MAX(last_active_at) last'), DB::raw('COUNT(*) n'))
                ->groupBy('user_id', 'platform')->get();
            foreach ($rows as $r) {
                $out[(int) $r->user_id][$r->platform] = ['build' => $r->build !== null ? (int) $r->build : null, 'last' => $r->last, 'phones' => (int) $r->n];
            }
            /* The app signed in on a phone that has not allowed notifications has no push address but reports itself
               (device_presence, 2026-11-02): it is installed all the same. */
            try {
                $pres = DB::table('device_presence')->whereIn('user_id', $chunk)
                    ->select('user_id', 'platform', DB::raw('MAX(app_build) build'), DB::raw('MAX(last_seen_at) last'), DB::raw('COUNT(*) n'))
                    ->groupBy('user_id', 'platform')->get();
                foreach ($pres as $r) {
                    if (isset($out[(int) $r->user_id][$r->platform])) continue;
                    $out[(int) $r->user_id][$r->platform] = ['build' => $r->build !== null ? (int) $r->build : null, 'last' => $r->last, 'phones' => (int) $r->n];
                }
            } catch (\Throwable $e) {}
        }
        return $out;
    }

    /** Counts for a set of people: Android app, iPhone app, no app (each person once in "none"). */
    public static function counts(array $userIds): array
    {
        $map = self::forUsers($userIds);
        $android = 0; $ios = 0;
        foreach ($map as $p) { if (isset($p['android'])) $android++; if (isset($p['ios'])) $ios++; }
        $total = count(array_unique(array_filter(array_map('intval', $userIds))));
        return ['android' => $android, 'ios' => $ios, 'none' => max(0, $total - count($map)), 'with_app' => count($map), 'total' => $total];
    }
}
