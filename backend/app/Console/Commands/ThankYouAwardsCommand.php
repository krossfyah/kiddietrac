<?php

namespace App\Console\Commands;

use App\Support\ThankYouAuto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * kt:thank-you-awards — the automatic monthly thank-you awards (App\Support\ThankYouAuto). Every 15 minutes; each
 * agency with the setting on is processed once its chosen day and time (agency time zone) has passed, for the
 * previous month, once. Also expires drafts nobody sent.
 *   --dry       show who would qualify, change nothing
 *   --agency=N  one agency (with --dry, works even when the setting is off)
 *   --force     ignore the schedule moment (still once per month)
 */
class ThankYouAwardsCommand extends Command
{
    protected $signature = 'kt:thank-you-awards {--agency=} {--dry} {--force}';
    protected $description = 'Automatic monthly thank-you awards to educators (drafts for review, or sent)';

    public function handle(): int
    {
        @set_time_limit(600);
        $dry = (bool) $this->option('dry');
        $only = $this->option('agency') ? (int) $this->option('agency') : null;
        $out = ['expired' => $dry ? 0 : ThankYouAuto::expire(), 'agencies' => []];
        $ids = $only ? [$only] : DB::table('agencies')->whereNull('deleted_at')->pluck('settings', 'id')
            ->filter(fn ($s) => (bool) ((json_decode((string) $s, true) ?: [])[ThankYouAuto::KEY]['enabled'] ?? false))->keys()->all();
        foreach ($ids as $aid) {
            try {
                $out['agencies'][] = ThankYouAuto::runAgency((int) $aid, $dry, (bool) $this->option('force'));
            } catch (\Throwable $e) {
                report($e);
                $out['agencies'][] = ['agency' => $aid, 'error' => $e->getMessage()];
            }
        }
        $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}
