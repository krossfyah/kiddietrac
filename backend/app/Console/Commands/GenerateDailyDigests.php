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
                            {--date= : Override the date (YYYY-MM-DD)}
                            {--no-notify : Build the digests only; do not push parents (they get the daily summary)}';

    protected $description = 'Generate AI daily digests for all enrolled children';

    public function handle(AiDigestService $service): int
    {
        $date = $this->option('date') ?: \App\Support\AgencyTime::today();
        $centreId = $this->option('centre');

        // Onboarding centres run real days too (the status page lists them); only active-only left them out (2026-10-30).
        $centres = Centre::whereIn('status', ['active', 'onboarding'])
            ->when($centreId, fn($q) => $q->where('id', $centreId))
            ->get();

        $totals = ['generated' => 0, 'skipped' => 0, 'failed' => 0];
        $started = now();

        foreach ($centres as $centre) {
            $this->info("Processing {$centre->name}...");
            /* Name the agency for the AI gate and the meter (2026-11-24): with no agency named, a console AI call
               passed AiConfig::offFor, so test agencies' digests used AI despite being switched off. */
            \App\Support\UsageMeter::forAgency((int) $centre->agency_id ?: null);
            $stats = $service->generateForCentre($centre->id, $date);
            $this->info("  Generated: {$stats['generated']}, Skipped: {$stats['skipped']}, Failed: {$stats['failed']}");

            $totals['generated'] += $stats['generated'];
            $totals['skipped'] += $stats['skipped'];
            $totals['failed'] += $stats['failed'];
        }

        \App\Support\UsageMeter::forAgency(null);   // never leak the last centre's agency into the next command
        $elapsed = (int) round($started->diffInSeconds(now(), true));
        $this->line('');
        $this->info("──────────────────────────────────────");
        $this->info("Digest generation complete in {$elapsed}s");
        $this->table(
            ['Generated', 'Skipped (no events)', 'Failed'],
            [[$totals['generated'], $totals['skipped'], $totals['failed']]]
        );

        // Trigger push notifications to parents - not when scheduled: parents already get the daily summary after
        // check-out, and Anthony chose "build digests, no parent push" (2026-10-30).
        if (! $this->option('no-notify')) {
            \App\Jobs\NotifyParentsOfDigests::dispatch($date);
        }

        return $totals['failed'] > 0 ? 1 : 0;
    }
}
