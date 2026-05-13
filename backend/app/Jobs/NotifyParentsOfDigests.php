<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * NotifyParentsOfDigests
 *
 * Dispatched by GenerateDailyDigests at the end of each bulk run. In v19,
 * this is intentionally a no-op (log only) — we want to verify digest quality
 * at scale before pushing notifications to every parent.
 *
 * Parents will see the digest naturally when they next open the app — the
 * Today screen calls /parent/children/{id}/digest/{date} which returns the
 * cached body from ai_daily_digests.
 *
 * v20 will replace this with real push delivery using the existing v14 push
 * client (VAPID keys are already provisioned, just gated until digest content
 * is QA'd at scale).
 */
class NotifyParentsOfDigests implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $date) {}

    public function handle(): void
    {
        try {
            $count = DB::table('ai_daily_digests')
                ->whereDate('digest_date', $this->date)
                ->count();

            Log::info(sprintf(
                'v19 digest run complete for %s: %d digests generated. ' .
                'Parents will see them on next app open. Push delivery deferred to v20.',
                $this->date,
                $count
            ));
        } catch (\Throwable $e) {
            Log::error('NotifyParentsOfDigests failed: ' . $e->getMessage());
        }
    }
}
