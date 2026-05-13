<?php

namespace App\Console\Commands;

use App\Models\Centre;
use App\Services\AiDigestService;
use Illuminate\Console\Command;

/**
 * Generates AI daily digests for all enrolled children at every active centre.
 *
 * Usage:
 *   php artisan kiddietrac:generate-digests
 *   php artisan kiddietrac:generate-digests --centre=1
 *   php artisan kiddietrac:generate-digests --date=2026-05-10
 *
 * Cost estimate: ~$0.001 per digest with claude-haiku-4-5
 * For an agency with 500 enrolled children: ~$0.50/day = $15/month
 */
class GenerateDailyDigests extends Command
{
    protected $signature = 'kiddietrac:generate-digests
                            {--centre= : Generate for one centre only}
                            {--date= : Override the date (YYYY-MM-DD)}';

    protected $description = 'Generate AI daily digests for all enrolled children';

    public function handle(AiDigestService $service): int
    {
        $date = $this->option('date') ?: today()->toDateString();
        $centreId = $this->option('centre');

        $centres = Centre::where('status', 'active')
            ->when($centreId, fn($q) => $q->where('id', $centreId))
            ->get();

        $totals = ['generated' => 0, 'skipped' => 0, 'failed' => 0];
        $started = now();

        foreach ($centres as $centre) {
            $this->info("Processing {$centre->name}...");
            $stats = $service->generateForCentre($centre->id, $date);
            $this->info("  Generated: {$stats['generated']}, Skipped: {$stats['skipped']}, Failed: {$stats['failed']}");

            $totals['generated'] += $stats['generated'];
            $totals['skipped'] += $stats['skipped'];
            $totals['failed'] += $stats['failed'];
        }

        $elapsed = now()->diffInSeconds($started);
        $this->line('');
        $this->info("──────────────────────────────────────");
        $this->info("Digest generation complete in {$elapsed}s");
        $this->table(
            ['Generated', 'Skipped (no events)', 'Failed'],
            [[$totals['generated'], $totals['skipped'], $totals['failed']]]
        );

        // Trigger push notifications to parents
        \App\Jobs\NotifyParentsOfDigests::dispatch($date);

        return $totals['failed'] > 0 ? 1 : 0;
    }
}
