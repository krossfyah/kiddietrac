<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Which drills a centre owes, from its jurisdiction's rules (2026-10-09).
 *
 * A rule is ['type', 'every' => month|quarter|half_year, 'months'? => [allowed months],
 * 'only_if'? => 'transport']. For each rule and centre this answers two questions, in the
 * agency's own timezone: is this period's drill DONE yet, and was LAST period's MISSED.
 * A rule limited to some months (Ohio's weather drills, March–September) is not owed in
 * the others. "only_if: transport" rules are listed as optional — KiddieTrac does not yet
 * know which programs transport children.
 */
final class DrillRules
{
    public const TYPES = ['fire' => 'Fire', 'weather' => 'Severe weather', 'lockdown' => 'Lockdown', 'vehicle_exit' => 'Vehicle evacuation', 'shelter' => 'Shelter-in-place', 'other' => 'Other'];

    /** [start, end) of the period containing $at, in the agency's timezone. */
    public static function period(string $every, Carbon $at): array
    {
        $a = $at->copy();
        if ($every === 'quarter') { $s = $a->copy()->firstOfQuarter()->startOfDay(); return [$s, $s->copy()->addMonths(3)]; }
        if ($every === 'half_year') { $s = $a->copy()->month($a->month <= 6 ? 1 : 7)->startOfMonth()->startOfDay(); return [$s, $s->copy()->addMonths(6)]; }
        $s = $a->copy()->startOfMonth()->startOfDay();

        return [$s, $s->copy()->addMonth()];
    }

    private static function owedIn(array $rule, Carbon $periodStart): bool
    {
        if (empty($rule['months'])) return true;
        // A monthly rule limited to some months is owed only in those months.
        return in_array((int) $periodStart->month, $rule['months'], true);
    }

    private static function periodLabel(string $every, Carbon $s): string
    {
        if ($every === 'quarter') return 'Q' . $s->quarter . ' ' . $s->year;
        if ($every === 'half_year') return ($s->month <= 6 ? 'Jan–Jun ' : 'Jul–Dec ') . $s->year;

        return $s->format('F Y');
    }

    /** Status per rule for one centre: [{type, label, every, optional, current: {period, done_at|null, owed}, previous: {period, missed, owed}}]. */
    public static function status(int $agencyId, int $centreId, ?Carbon $now = null): array
    {
        $tz = AgencyTime::tz($agencyId);
        $now = ($now ?: now())->copy()->setTimezone($tz);
        $rules = Jurisdictions::forAgency($agencyId)['drills'] ?? [];
        $out = [];
        foreach ($rules as $rule) {
            $every = $rule['every'] ?? 'month';
            [$cs, $ce] = self::period($every, $now);
            [$ps, $pe] = self::period($every, $cs->copy()->subDay());
            $find = function (Carbon $s, Carbon $e) use ($centreId, $rule) {
                return DB::table('drills')->where('centre_id', $centreId)->where('type', $rule['type'])->whereNull('deleted_at')
                    ->where('drilled_at', '>=', $s->copy()->utc())->where('drilled_at', '<', $e->copy()->utc())->max('drilled_at');
            };
            $curOwed = self::owedIn($rule, $cs);
            $prevOwed = self::owedIn($rule, $ps);
            $curDone = $curOwed ? $find($cs, $ce) : null;
            $prevDone = $prevOwed ? $find($ps, $pe) : null;
            $out[] = [
                'type' => $rule['type'], 'label' => $rule['label'] ?? (self::TYPES[$rule['type']] ?? $rule['type']), 'every' => $every,
                'optional' => ! empty($rule['only_if']),
                'current' => ['period' => self::periodLabel($every, $cs), 'owed' => $curOwed, 'done_at' => $curDone],
                'previous' => ['period' => self::periodLabel($every, $ps), 'owed' => $prevOwed, 'missed' => $prevOwed && ! $prevDone],
            ];
        }

        return $out;
    }
}
