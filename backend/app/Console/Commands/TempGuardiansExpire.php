<?php

namespace App\Console\Commands;

use App\Support\TempGuardians;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remove UNUSED temporary (forms-only) parent accounts past each agency's limit
 * (Settings → Notifications → Forms: "remove after N days", default 30, 0 = never).
 * Unused = never signed in and never signed a form; anything else is only flagged in the
 * admin digest. See App\Support\TempGuardians.
 */
class TempGuardiansExpire extends Command
{
    protected $signature = 'kt:temp-guardians-expire {--agency=} {--dry-run}';
    protected $description = 'Remove unused temporary (forms-only) parent accounts past the agency limit';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $agencies = DB::table('agencies')->when($this->option('agency'), fn ($q) => $q->where('id', (int) $this->option('agency')))->pluck('id');
        $total = 0;
        foreach ($agencies as $agencyId) {
            $days = TempGuardians::settings((int) $agencyId)['remove_after_days'];
            foreach (TempGuardians::due((int) $agencyId) as $r) {
                $this->line(($dry ? '  would remove ' : '  removing ') . "#{$r['id']} {$r['email']} (agency {$agencyId}, created {$r['created_at']})");
                if (! $dry) TempGuardians::remove((int) $agencyId, (int) $r['id'], null, "unused for {$days} days (no sign-in, no forms signed)");
                $total++;
            }
        }
        $this->info(($dry ? 'Would remove ' : 'Removed ') . $total . ' account(s)');

        return self::SUCCESS;
    }
}
