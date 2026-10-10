<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AgencyTime;
use App\Support\Notify;
use App\Support\OhioPfcc;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ohio PFCC daily alerts (2026-10-10) — BELL ONLY, to the centre's directors and the agency
 * admins. Never email, SMS or anything to families.
 *   - copay unpaid for N finished weeks (setting, default 2): Ohio expects non-payment past
 *     2 weeks to be reported to the county within 3 weeks. Once per authorization per week.
 *   - a child within 2 days of (or over) the half-year absent-day cap. Once per child per
 *     half-year for "near" and once for "over".
 */
final class PfccAlertsCommand extends Command
{
    protected $signature = 'pfcc:alerts {--agency= : one agency only} {--dry : print, write nothing}';
    protected $description = 'Ohio PFCC: unpaid copay and absent-day cap alerts (bell only, staff only)';

    public function handle(): int
    {
        $agencies = DB::table('agencies')->whereNull('deleted_at')->when($this->option('agency'), fn ($q, $id) => $q->where('id', (int) $id))->pluck('id');
        $dry = (bool) $this->option('dry');
        $sent = 0;
        foreach ($agencies as $agencyId) {
            $agencyId = (int) $agencyId;
            if (! OhioPfcc::pfccOn($agencyId)) continue;
            $today = AgencyTime::today($agencyId);
            $set = OhioPfcc::settings($agencyId);
            [, , $half] = OhioPfcc::halfYear($today);
            $week = OhioPfcc::weekStart($today);
            $auths = DB::table('pfcc_authorizations as a')->join('children as c', 'c.id', '=', 'a.child_id')
                ->whereIn('a.centre_id', \App\Support\Live::centreIds($agencyId) ?: [0])
                ->where('a.agency_id', $agencyId)->whereNull('a.ended_at')->whereNull('c.deleted_at')->whereNull('c.withdrawn_at')
                ->where('a.starts_on', '<=', $today)->where(fn ($q) => $q->whereNull('a.ends_on')->orWhere('a.ends_on', '>=', $today))
                ->get(['a.*', 'c.first_name', 'c.last_name', 'c.preferred_name']);
            foreach ($auths as $a) {
                $name = trim(($a->preferred_name ?: $a->first_name) . ' ' . $a->last_name);
                if (! $dry) OhioPfcc::ensureCopayWeeks($a, $today);
                $unpaid = OhioPfcc::unpaidWeeks($a, $today);
                if ($unpaid >= (int) $set['alert_unpaid_weeks']) {
                    $sent += $this->bell($agencyId, (int) $a->centre_id, 'copay_unpaid', (int) $a->id, $week, $dry,
                        'PFCC copay unpaid: ' . $name,
                        $name . "'s family has not paid the PFCC copay for $unpaid week(s). Ohio expects non-payment past 2 weeks to be reported to the county within 3 weeks.",
                        ['authorization_id' => (int) $a->id, 'child_id' => (int) $a->child_id]);
                }
                $abs = OhioPfcc::absentDays((int) $a->child_id, $today);
                $cap = (int) $set['absent_cap'];
                $kind = $abs > $cap ? 'absent_over_cap' : ($abs >= $cap - 2 ? 'absent_near_cap' : null);
                if ($kind) {
                    $sent += $this->bell($agencyId, (int) $a->centre_id, $kind, (int) $a->child_id, $half, $dry,
                        ($kind === 'absent_over_cap' ? 'PFCC absent days over the limit: ' : 'PFCC absent days near the limit: ') . $name,
                        "$name has $abs absent day(s) this half-year ($half); your limit is set to $cap. Check the child's authorization in TAP.",
                        ['child_id' => (int) $a->child_id]);
                }
            }
        }
        $this->info(($dry ? '[dry] ' : '') . "pfcc alerts: $sent");

        return self::SUCCESS;
    }

    private function bell(int $agencyId, int $centreId, string $kind, int $refId, string $period, bool $dry, string $title, string $body, array $data): int
    {
        if (DB::table('pfcc_alerts')->where(['kind' => $kind, 'ref_id' => $refId, 'period' => $period])->exists()) return 0;
        $users = DB::table('role_assignments')->where('active', true)
            ->where(fn ($w) => $w->where(fn ($d) => $d->where('role', 'centre_director')->where('centre_id', $centreId))
                ->orWhere(fn ($d) => $d->where('role', 'agency_admin')->where('agency_id', $agencyId)))
            ->distinct()->pluck('user_id')->all();
        if ($dry) { $this->line("would bell " . count($users) . " for $kind #$refId ($period): $title"); return 1; }
        if (! DB::table('pfcc_alerts')->insertOrIgnore(['agency_id' => $agencyId, 'ref_id' => $refId, 'kind' => $kind, 'period' => $period, 'created_at' => now()])) return 0;
        Notify::write(array_map(fn ($u) => ['user_id' => (int) $u, 'type' => 'pfcc_' . $kind, 'title' => $title, 'body' => $body,
            'data' => json_encode($data + ['hash' => 'pfcc', 'agency_id' => $agencyId]), 'created_at' => now()], $users));

        return 1;
    }
}
