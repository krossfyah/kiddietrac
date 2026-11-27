<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AgencyTime;
use App\Support\Features;
use App\Support\HrDocs;
use App\Support\HrLeave;
use App\Support\HrPay;
use App\Support\TestAgency;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * KiddieTrac HR Phase 4, batch 4 (2026-11-27). Hourly at :35, one process. Per agency with the HR add-on (Features::skips):
 *  1. pay / role / hours changes dated today or earlier (agency timezone) apply themselves (App\Support\HrPay). Data only.
 *  2. document expiry + follow-up reminders (App\Support\HrDocs), once per step, from 07:00 agency time. Test agencies
 *     send nothing (their reminders are not even stamped, so nothing is lost if the flag is ever lifted).
 */
final class HrFileTasksCommand extends Command
{
    protected $signature = 'hr:file-tasks {--agency= : one agency id only} {--dry-run : count what would happen, change and send nothing}';

    protected $description = 'KiddieTrac HR: apply dated pay/role changes and send document expiry / follow-up reminders';

    public function handle(): int
    {
        $only = $this->option('agency') ? (int) $this->option('agency') : null;
        $dry = (bool) $this->option('dry-run');
        $ids = DB::table('agencies')->whereNull('deleted_at')->when($only, fn ($q) => $q->where('id', $only))->pluck('id')->map(fn ($v) => (int) $v)->all();
        foreach ($ids as $aid) {
            if (! Features::on($aid, HrLeave::FEATURE)) {
                if ($only) Features::skips($aid, HrLeave::FEATURE, 'HR file tasks');
                continue;
            }
            try {
                $applied = HrPay::applyDue($aid, $dry);
                [$bells, $mails] = [0, 0];
                $hour = (int) AgencyTime::now($aid)->format('G');
                if ($hour >= 7 || $dry) {
                    if (TestAgency::isAgency($aid) && ! $dry) {
                        if ($only) $this->line("agency $aid: test agency, reminders send nothing");
                    } else {
                        [$bells, $mails] = HrDocs::remind($aid, $dry);
                    }
                }
                if ($applied || $bells || $only) $this->line("agency $aid: " . ($dry ? 'would apply ' : 'applied ') . "$applied change(s), " . ($dry ? 'would ring ' : 'rang ') . "$bells bell(s), $mails email(s)");
            } catch (\Throwable $e) {
                $this->error("agency $aid: " . $e->getMessage());
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
