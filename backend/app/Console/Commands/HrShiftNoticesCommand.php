<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Features;
use App\Support\HrLeave;
use App\Support\HrShiftNotices;
use App\Support\TestAgency;
use Illuminate\Console\Command;

/**
 * KiddieTrac HR Phase 4, batch 3 (2026-11-27): send the waiting shift notices (App\Support\HrShiftNotices), ONE per
 * person per quiet batch of changes. Runs in kt:minute two. Agencies without the HR add-on (Features::skips) or with
 * notices off have their queue closed unsent; a test agency sends nothing (closed with outcome "test").
 */
final class HrShiftNoticesCommand extends Command
{
    protected $signature = 'hr:shift-notices {--agency= : one agency id only} {--dry-run : list who would be told and what, send nothing}';

    protected $description = 'KiddieTrac HR: one notice per person per batch of shift changes (bell + phone + email)';

    public function handle(): int
    {
        $only = $this->option('agency') ? (int) $this->option('agency') : null;
        $dry = (bool) $this->option('dry-run');
        foreach (HrShiftNotices::pendingAgencies($only) as $aid) {
            try {
                if (Features::skips($aid, HrLeave::FEATURE, 'HR shift notices')) {
                    if (! $dry) HrShiftNotices::close($aid, 'off');
                    continue;
                }
                if (! HrShiftNotices::settings($aid)['enabled']) {
                    if (! $dry) HrShiftNotices::close($aid, 'off');
                    continue;
                }
                if (TestAgency::isAgency($aid) && ! $dry) {
                    TestAgency::skips($aid, 'HR shift notices');
                    $n = HrShiftNotices::close($aid, 'test');
                    if ($n) $this->line("agency $aid: test agency, sends nothing ($n change(s) closed)");
                    continue;
                }
                $r = HrShiftNotices::sendAgency($aid, $dry);
                if ($r['people'] || $only) {
                    $this->line("agency $aid: " . ($dry ? 'would notify ' : 'notified ') . ($dry ? $r['people'] : $r['sent']) . ' person(s)' . ($r['nothing'] ? ', ' . $r['nothing'] . ' with nothing left to say' : ''));
                }
                if ($dry) {
                    foreach ($r['preview'] as $p) {
                        $this->line('  user ' . $p['user_id'] . ': ' . $p['changes'] . ' change row(s) -> ' . count($p['lines']) . ' line(s)'
                            . ($p['lines'] ? ' | ' . HrShiftNotices::title($p['lines']) : ''));
                    }
                }
            } catch (\Throwable $e) {
                $this->error("agency $aid: " . $e->getMessage());
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
