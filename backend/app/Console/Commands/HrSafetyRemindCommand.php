<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Features;
use App\Support\HrLeave;
use App\Support\HrSafety;
use App\Support\TestAgency;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * KiddieTrac HR Phase 4, batch 7 (2026-11-27): health & safety reminders, once a morning (App\Support\HrSafety::remind).
 *  - risk assessments: review due in 14 days, on the day, then weekly while overdue -> the centre's directors (else the
 *    agency admins) and the owner;
 *  - safety data sheets: review date the same way, and once when an SDS passes 3 years old -> the centre's managers;
 *  - named H&S duties whose certificate is expiring (30 days), expired or missing -> the person and their centre's managers.
 * Bell + phone only (Notify::write type hr; team rows follow StaffNotifyPrefs staff_hr). Once per step per record.
 * HR add-on agencies only (Features::skips); test agencies send nothing.
 */
final class HrSafetyRemindCommand extends Command
{
    protected $signature = 'hr:safety-remind {--agency= : one agency id only} {--dry-run : count what it would send, send nothing}';

    protected $description = 'KiddieTrac HR: risk assessment reviews, safety data sheet reviews and H&S duty certificates (bell + phone)';

    public function handle(): int
    {
        if (! HrSafety::ready()) { $this->line('tables not there yet'); return self::SUCCESS; }
        $only = $this->option('agency') ? (int) $this->option('agency') : null;
        $dry = (bool) $this->option('dry-run');
        $ids = collect(['hr_risk_assessments', 'hr_chemicals', 'hr_safety_duties'])
            ->flatMap(fn ($t) => DB::table($t)->when($only, fn ($q) => $q->where('agency_id', $only))->distinct()->pluck('agency_id'))
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
        foreach ($ids as $aid) {
            if (! DB::table('agencies')->where('id', $aid)->whereNull('deleted_at')->exists()) continue;
            if (Features::skips($aid, HrLeave::FEATURE, 'H&S reminders')) { $this->line("agency $aid: HR add-on off, skipped"); continue; }
            if (TestAgency::isAgency($aid) && ! $dry) { $this->line("agency $aid: test agency, sends nothing"); continue; }
            try {
                $r = HrSafety::remind($aid, $dry);
                $this->line("agency $aid: " . ($dry ? 'would send ' : 'sent ') . $r['bells'] . ' bell(s) - risk ' . $r['risk'] . ', SDS ' . $r['chem'] . ', duties ' . $r['duty']);
            } catch (\Throwable $e) {
                $this->error("agency $aid: " . $e->getMessage());
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
