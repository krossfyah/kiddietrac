<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Features;
use App\Support\HrLeave;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * KiddieTrac HR: the nightly leave grants, pay-period and hours-worked accruals, carry-over and expiry (HrLeave).
 * Writes ledger rows only (each with a unique source key, so a rerun adds nothing). Sends nothing.
 * Only agencies with the HR add-on (Features::skips) and at least one leave type.
 */
final class HrLeaveAccrueCommand extends Command
{
    protected $signature = 'hr:leave-accrue {--agency= : one agency id only} {--dry-run : count what it would write, write nothing}';

    protected $description = 'KiddieTrac HR: grant and accrue leave, carry over and expire balances (agency rules, agency dates)';

    public function handle(): int
    {
        $only = $this->option('agency') ? (int) $this->option('agency') : null;
        $dry = (bool) $this->option('dry-run');
        $ids = DB::table('hr_leave_types')->whereNull('archived_at')->when($only, fn ($q) => $q->where('agency_id', $only))
            ->distinct()->pluck('agency_id')->map(fn ($v) => (int) $v)->all();
        foreach ($ids as $aid) {
            if (! DB::table('agencies')->where('id', $aid)->whereNull('deleted_at')->exists()) {
                continue;
            }
            if (Features::skips($aid, HrLeave::FEATURE, 'HR leave accruals')) {
                $this->line("agency $aid: HR add-on off, skipped");
                continue;
            }
            try {
                $r = HrLeave::accrueAgency($aid, $dry);
                $this->line("agency $aid: " . ($dry ? 'would write ' : 'wrote ') . $r['rows'] . ' row(s) for ' . $r['people'] . ' people');
            } catch (\Throwable $e) {
                $this->error("agency $aid: " . $e->getMessage());
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
