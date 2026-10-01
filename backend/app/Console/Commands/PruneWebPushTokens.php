<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Keep browser push registrations to the ones that can still be reached (2026-10-01).
 *
 * WebPushService only purges a subscription when the push service answers 404/410, so
 * anything that is never told it is gone stays forever: one account had 292 rows, every
 * one of them sent to on every notification. Three rules, in order:
 *
 *   1. not a subscription at all — a web row whose token is not the JSON
 *      {endpoint, keys} object (login used to write a random 80-character string);
 *   2. idle — not refreshed for --days (push-client re-posts its subscription on every
 *      load where permission is granted, which bumps last_active_at);
 *   3. too many — beyond the --keep most recently active per person.
 *
 * Android/iOS tokens are not touched: FCM prunes those itself (404 → delete).
 *
 *   php artisan push:prune-web [--days=60] [--keep=10] [--dry-run]
 */
class PruneWebPushTokens extends Command
{
    protected $signature = 'push:prune-web {--days=60 : idle days after which a browser registration goes} {--keep=10 : most recent registrations kept per person} {--dry-run : report, delete nothing}';

    protected $description = 'Remove unusable, idle and surplus browser push registrations';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $days = max(7, (int) $this->option('days'));
        $keep = max(1, (int) $this->option('keep'));
        $web = fn () => DB::table('device_tokens')->where('platform', 'web');

        $junk = $web()->where('token', 'not like', '{%')->pluck('id')->all();

        $cut = now()->subDays($days);
        $idle = $web()->whereNotIn('id', $junk ?: [0])
            ->where(function ($q) use ($cut) {
                $q->where('last_active_at', '<', $cut)
                  ->orWhere(fn ($q2) => $q2->whereNull('last_active_at')->where('created_at', '<', $cut));
            })->pluck('id')->all();

        $gone = array_merge($junk, $idle);
        $surplus = [];
        $rows = $web()->whereNotIn('id', $gone ?: [0])
            ->orderBy('user_id')->orderByDesc('last_active_at')->orderByDesc('id')
            ->get(['id', 'user_id']);
        $seen = [];
        foreach ($rows as $r) {
            $n = ($seen[$r->user_id] = ($seen[$r->user_id] ?? 0) + 1);
            if ($n > $keep) {
                $surplus[] = $r->id;
            }
        }

        $this->line(($dry ? '[dry] ' : '') . sprintf('not a subscription: %d, idle %d+ days: %d, beyond %d per person: %d',
            count($junk), $days, count($idle), $keep, count($surplus)));

        $all = array_merge($gone, $surplus);
        if (! $dry && $all) {
            foreach (array_chunk($all, 500) as $chunk) {
                DB::table('device_tokens')->whereIn('id', $chunk)->delete();
            }
        }

        return self::SUCCESS;
    }
}
