<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ONTARIO DRILL CADENCE (2026-10-13; O. Reg. 137/15 as amended by O. Reg. 197/26).
 *
 *   s.68.2  a missing-child drill at least every 3 months (in force 2027-01-01), records
 *           kept at least 12 months. Rolling: next one is due 3 months after the last.
 *   s.68    fire drills monthly. A school-based program may instead run 3 per school term
 *           (centre setting `drill_cadence.fire` = 'school_term' in centres.settings).
 *
 * Only CA-ON agencies get this — everywhere else DrillRules keeps doing what it did, and
 * the missing-child type is not offered. Dates are the AGENCY's calendar dates.
 *
 * Retention: drill rows are never hard-deleted by any purge job (RetentionPurgeCommand
 * does not touch `drills`; removing a drill in the screen is a soft delete), so the
 * 12-month floor holds without a job of its own.
 *
 * Each centre's status is [type => {type, label, rule, state, last_date, due_on,
 * days_left, never, count?, needed?}] where state is ok | due | due_soon | overdue.
 */
final class DrillCadence
{
    public const IN_FORCE = '2027-01-01';
    public const MISSING_CHILD_MONTHS = 3;
    public const DUE_SOON_DAYS = 14;
    public const RETENTION_MONTHS = 12;
    public const FIRE_CADENCES = ['monthly' => 'Monthly', 'school_term' => '3 per school term (school-based program)'];

    public static function applies(?int $agencyId): bool
    {
        try {
            return Jurisdictions::codeFor($agencyId) === 'CA-ON';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Drill types offered to this agency: the usual ones, plus Missing child in Ontario. */
    public static function types(int $agencyId): array
    {
        if (! self::applies($agencyId)) return DrillRules::TYPES;
        $out = [];
        foreach (DrillRules::TYPES as $k => $v) {
            $out[$k] = $v;
            if ($k === 'fire') $out['missing_child'] = 'Missing child';
        }

        return $out;
    }

    public static function label(string $type): string
    {
        return $type === 'missing_child' ? 'Missing child' : (DrillRules::TYPES[$type] ?? $type);
    }

    /** The centre's fire cadence: 'monthly' (default) or 'school_term'. */
    public static function fireCadence(int $centreId): string
    {
        $raw = DB::table('centres')->where('id', $centreId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $v = (string) ($s['drill_cadence']['fire'] ?? 'monthly');

        return isset(self::FIRE_CADENCES[$v]) ? $v : 'monthly';
    }

    /** @return array<string, array> empty when the agency is not in Ontario. */
    public static function status(int $agencyId, int $centreId, ?Carbon $now = null): array
    {
        if (! self::applies($agencyId)) return [];
        $tz = AgencyTime::tz($agencyId);
        $now = ($now ?: now())->copy()->setTimezone($tz);
        $today = $now->copy()->startOfDay();

        return [
            'missing_child' => self::missingChild($centreId, $tz, $today),
            'fire' => self::fireCadence($centreId) === 'school_term' && $today->month !== 7 && $today->month !== 8
                ? self::fireTerm($centreId, $tz, $today)
                : self::fireMonthly($centreId, $tz, $today),
        ];
    }

    private static function lastAt(int $centreId, string $type, ?Carbon $from = null, ?Carbon $to = null): ?Carbon
    {
        $q = DB::table('drills')->where('centre_id', $centreId)->where('type', $type)->whereNull('deleted_at');
        if ($from) $q->where('drilled_at', '>=', $from->copy()->utc());
        if ($to) $q->where('drilled_at', '<', $to->copy()->utc());
        $v = $q->max('drilled_at');

        return $v ? Carbon::parse($v, 'UTC') : null;
    }

    private static function shape(string $type, string $rule, ?Carbon $last, Carbon $due, Carbon $today, string $tz, ?string $forced = null): array
    {
        $days = (int) $today->diffInDays($due->copy()->startOfDay(), false);
        $state = $forced ?: ($days < 0 ? 'overdue' : ($days <= self::DUE_SOON_DAYS ? 'due_soon' : 'ok'));

        return ['type' => $type, 'label' => self::label($type) . ' drill', 'rule' => $rule, 'state' => $state,
            'last_date' => $last ? $last->copy()->setTimezone($tz)->toDateString() : null,
            'due_on' => $due->toDateString(), 'days_left' => $days, 'never' => $last === null];
    }

    /** s.68.2: rolling 3 months from the last one. None yet: due by the in-force date. */
    private static function missingChild(int $centreId, string $tz, Carbon $today): array
    {
        $last = self::lastAt($centreId, 'missing_child');
        $due = $last ? $last->copy()->setTimezone($tz)->startOfDay()->addMonthsNoOverflow(self::MISSING_CHILD_MONTHS)
            : Carbon::createFromFormat('Y-m-d', self::IN_FORCE, $tz)->startOfDay();

        return self::shape('missing_child', 'At least every 3 months (s.68.2, from Jan 1, 2027)', $last, $due, $today, $tz);
    }

    /** s.68: one a calendar month. Overdue = last month went by without one (and none yet this month). */
    private static function fireMonthly(int $centreId, string $tz, Carbon $today): array
    {
        $ms = $today->copy()->startOfMonth();
        $last = self::lastAt($centreId, 'fire');
        $thisMonth = self::lastAt($centreId, 'fire', $ms, $ms->copy()->addMonth());
        if ($thisMonth) {
            $r = self::shape('fire', 'Monthly (s.68)', $last, $ms->copy()->addMonthNoOverflow()->endOfMonth()->startOfDay(), $today, $tz, 'ok');
        } else {
            $due = $ms->copy()->endOfMonth()->startOfDay();
            $missed = $last !== null && $last->lt($ms->copy()->subMonthNoOverflow()->utc());
            $days = (int) $today->diffInDays($due, false);
            $r = self::shape('fire', 'Monthly (s.68)', $last, $due, $today, $tz, $missed ? 'overdue' : ($days <= 7 ? 'due_soon' : 'due'));
            $r['missed_month'] = $missed ? $ms->copy()->subMonthNoOverflow()->format('F Y') : null;
        }

        return $r;
    }

    /** School-based programs: 3 a term (Sep–Dec, Jan–Jun), spread out — the 1st by a third of the way in, the 2nd by two thirds, the 3rd by term end. */
    private static function fireTerm(int $centreId, string $tz, Carbon $today): array
    {
        $ts = $today->month >= 9 ? $today->copy()->month(9)->startOfMonth() : $today->copy()->month(1)->startOfMonth();
        $te = $today->month >= 9 ? $ts->copy()->addMonths(4) : $ts->copy()->addMonths(6);
        $count = DB::table('drills')->where('centre_id', $centreId)->where('type', 'fire')->whereNull('deleted_at')
            ->where('drilled_at', '>=', $ts->copy()->utc())->where('drilled_at', '<', $te->copy()->utc())->count();
        $last = self::lastAt($centreId, 'fire');
        $len = $ts->diffInDays($te);
        $n = min(3, $count + 1);
        $due = $ts->copy()->addDays((int) floor($len * $n / 3) - 1)->startOfDay();
        // No fire drill on record yet: aim at the next checkpoint still ahead, not one already gone by.
        while ($last === null && $n < 3 && $due->lt($today)) { $n++; $due = $ts->copy()->addDays((int) floor($len * $n / 3) - 1)->startOfDay(); }
        $r = self::shape('fire', '3 per school term (s.68, school-based program)', $last, $due, $today, $tz, $count >= 3 ? 'ok' : null);
        // Before the first recorded fire drill a missed one cannot be told from one done on paper (as DrillRules).
        if ($last === null && $r['state'] === 'overdue') { $r['state'] = 'due_soon'; $r['due_on'] = $today->toDateString(); $r['days_left'] = 0; }
        $r['count'] = $count;
        $r['needed'] = 3;
        $r['term_end'] = $te->copy()->subDay()->toDateString();
        if ($count >= 3) $r['due_on'] = $r['term_end'];

        return $r;
    }
}
