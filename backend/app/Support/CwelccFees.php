<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ONTARIO CWELCC BASE FEES (2026-11-13, OCCMS sweep: "do we have everything for OCCMS built out").
 *
 * Since January 1, 2025 an Ontario CWELCC centre charges parents a BASE FEE: the lower of $22 a day or the daily fee
 * it charged on December 31, 2024, never below $12 a day (Ministry of Education, Ontario Child Care and EarlyON
 * Funding Guidelines, Ch. 2 Div. 2, CWELCC cost-based funding; the Sept 29, 2026 Canada-Ontario extension keeps the
 * $22 cap to at least June 30, 2027). Every MANDATORY fee counts toward it (a one-time fee is spread over the service
 * days); only optional services may be charged on top (non-base fees). Parents may be charged for at most 10
 * consecutive business days of closure and 20 a year, statutory holidays included when charged.
 *
 * The old model - a percentage off each family's fee (cwelcc_enrolments.subsidy_rate) - is the 2022-2024 revenue
 * replacement scheme. For an Ontario agency it no longer prices anything: the centre is funded by its cost-based
 * allocation, and what the family pays IS the base fee. So this does not compute a "reduction". It checks:
 *  - each CWELCC child's care fee as a daily figure against the centre's base fee for the age group;
 *  - other charges on CWELCC families' invoices (anything but care, subsidy and discounts), which are fine only if
 *    optional;
 *  - closure days this calendar year against the 10-in-a-row / 20-a-year limits.
 *
 * Daily figure: monthly fee ÷ (261 ÷ 12 × days a week ÷ 5) - 261 weekdays a year, the conversion CMSMs use (York fee
 * subsidy handbook: monthly ÷ 21.75). The December 31, 2024 fee is per centre and age group, entered by an admin
 * (centres.settings.cwelcc_base); until it is, the $22 cap is the limit and the row says so.
 */
final class CwelccFees
{
    public const CAP = 22.0;
    public const FLOOR = 12.0;
    public const YEAR_DAYS = 261;
    public const CLOSURE_RUN = 10;
    public const CLOSURE_YEAR = 20;
    public const GROUPS = ['infant', 'toddler', 'preschool', 'kindergarten', 'school_age'];

    /** Charges that are care itself, or take money off it - never a "non-base fee". */
    private const CARE_TYPES = ['tuition', 'care', 'childcare', 'base', 'subsidy', 'copay', 'co_pay', 'discount', 'credit', 'sibling_discount', 'cwelcc', 'adjustment', 'payment', 'refund'];

    public static function applies(int $agencyId): bool
    {
        return OccmsRoa::applies($agencyId);
    }

    /** ['dec2024' => [group => daily fee|null], 'cap' => 22, 'floor' => 12] */
    public static function settings(int $centreId): array
    {
        $raw = DB::table('centres')->where('id', $centreId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $b = is_array($s['cwelcc_base'] ?? null) ? $s['cwelcc_base'] : [];
        $dec = [];
        foreach (self::GROUPS as $g) {
            $v = $b['dec2024'][$g] ?? null;
            $dec[$g] = is_numeric($v) && (float) $v > 0 ? round((float) $v, 2) : null;
        }

        return ['dec2024' => $dec, 'cap' => self::CAP, 'floor' => self::FLOOR];
    }

    /** The base fee for an age group, or null when the December 31, 2024 fee has not been entered. */
    public static function base(array $set, ?string $group): ?float
    {
        $dec = $set['dec2024'][$group ?? ''] ?? null;
        if ($dec === null) return null;

        return round(max(self::FLOOR, min(self::CAP, (float) $dec)), 2);
    }

    /** Save the December 31, 2024 daily fees for a centre (null clears one). */
    public static function save(int $centreId, array $dec2024): array
    {
        DB::transaction(function () use ($centreId, $dec2024) {
            $raw = DB::table('centres')->where('id', $centreId)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $clean = [];
            foreach (self::GROUPS as $g) {
                $v = $dec2024[$g] ?? null;
                if (is_numeric($v) && (float) $v > 0) $clean[$g] = round((float) $v, 2);
            }
            $s['cwelcc_base'] = ['dec2024' => $clean];
            DB::table('centres')->where('id', $centreId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });

        return self::settings($centreId);
    }

    /** Daily equivalent of a monthly fee for a child booked $daysPerWeek days a week. */
    public static function daily(float $monthly, int $daysPerWeek): float
    {
        $days = self::YEAR_DAYS / 12 * max(1, min(5, $daysPerWeek)) / 5;

        return round($monthly / $days, 2);
    }

    /**
     * The month's check for one centre.
     * ['set' => settings, 'base' => [group => base|null], 'children' => [...], 'fees' => [...], 'closures' => [...], 'issues' => int]
     */
    public static function check(int $agencyId, int $centreId, string $month): array
    {
        $today = AgencyTime::today($agencyId);
        $ms = $month . '-01';
        $me = Carbon::parse($ms)->endOfMonth()->toDateString();
        $ref = min($me, $today);
        $set = self::settings($centreId);
        $bases = [];
        foreach (self::GROUPS as $g) $bases[$g] = self::base($set, $g);
        $centre = DB::table('centres')->where('id', $centreId)->first(['id', 'cwelcc_enrolled']);

        $rows = DB::table('enrollments as e')->join('rooms as r', 'r.id', '=', 'e.room_id')->join('children as ch', 'ch.id', '=', 'e.child_id')
            ->where('r.centre_id', $centreId)
            ->where('e.start_date', '<=', $ref)->where(fn ($w) => $w->whereNull('e.end_date')->orWhere('e.end_date', '>=', $ref))
            ->where(fn ($w) => $w->whereNull('ch.deleted_at')->orWhere('ch.deleted_at', '>', $ref . ' 23:59:59'))
            ->where(fn ($w) => $w->whereNull('ch.withdrawn_at')->orWhere('ch.withdrawn_at', '>=', $ref))
            ->where('ch.enrollment_status', '!=', 'waitlist')
            ->orderBy('e.start_date')
            ->get(['ch.id', 'ch.first_name', 'ch.last_name', 'ch.date_of_birth', 'ch.family_id', 'r.age_group', 'e.cwelcc_eligible', 'e.monthly_fee', 'e.schedule']);
        /* No family opt-in: in Ontario a CWELCC centre must give the base fee to EVERY eligible child (the
           centre enrols, not the family), so cwelcc_enrolments - the old per-family rate rows - does not gate this. */

        // One row per child: their fees added up across rooms, the most days booked.
        $kids = [];
        foreach ($rows as $r) {
            $id = (int) $r->id;
            $dpw = count(CareSchedule::daysOf($r->schedule ?? null)) ?: 5;
            if (! isset($kids[$id])) {
                $kids[$id] = ['r' => $r, 'fee' => 0.0, 'dpw' => 0];
            }
            $kids[$id]['fee'] += (float) $r->monthly_fee;
            $kids[$id]['dpw'] = max($kids[$id]['dpw'], $dpw);
        }

        $children = [];
        $issues = 0;
        $cwelccFams = [];
        foreach ($kids as $id => $k) {
            $r = $k['r'];
            $g = (string) $r->age_group;
            $dob = $r->date_of_birth ? substr((string) $r->date_of_birth, 0, 10) : null;
            $until = OccmsRoa::cwelccUntil($dob, $g);
            $eligible = $centre && $centre->cwelcc_enrolled && $until !== null && $until >= $ms && (int) $r->cwelcc_eligible !== 0;
            if (! $eligible) continue;
            $cwelccFams[(int) $r->family_id] = true;
            $daily = self::daily($k['fee'], $k['dpw']);
            $base = $bases[$g] ?? null;
            $limit = $base ?? self::CAP;
            $over = $k['fee'] > 0 && $daily > $limit + 0.005;
            if ($over) $issues++;
            $children[] = [
                'child_id' => $id, 'name' => trim($r->first_name . ' ' . $r->last_name), 'family_id' => (int) $r->family_id,
                'group' => $g, 'label' => OccmsRoa::AGE_GROUPS[$g] ?? ucfirst(str_replace('_', ' ', $g)),
                'monthly_fee' => round($k['fee'], 2), 'days_per_week' => $k['dpw'], 'daily' => $daily,
                'base' => $base, 'limit' => $limit, 'base_known' => $base !== null,
                'status' => $k['fee'] <= 0 ? 'no_fee' : ($over ? 'over' : 'ok'),
                'over_per_day' => $over ? round($daily - $limit, 2) : 0.0,
                'over_per_month' => $over ? round($k['fee'] - $limit * (self::YEAR_DAYS / 12 * $k['dpw'] / 5), 2) : 0.0,
            ];
        }
        usort($children, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        // Other charges on CWELCC families' invoices this month: fine only if they are for an optional service.
        $fees = [];
        if ($cwelccFams) {
            $inv = DB::table('invoices')->whereIn('family_id', array_keys($cwelccFams))->where('centre_id', $centreId)->whereNotIn('status', ['void', 'draft'])
                ->where(fn ($w) => $w->whereBetween('period_start', [$ms, $me])
                    ->orWhere(fn ($q) => $q->whereNull('period_start')->whereBetween('issued_at', [$ms . ' 00:00:00', $me . ' 23:59:59'])))
                ->get(['id', 'family_id', 'invoice_number']);
            if ($inv->isNotEmpty()) {
                $fam = DB::table('families')->whereIn('id', $inv->pluck('family_id'))->pluck('family_name', 'id');
                $byId = $inv->keyBy('id');
                foreach (DB::table('invoice_lines')->whereIn('invoice_id', $inv->pluck('id'))->where('amount', '>', 0)
                    ->get(['invoice_id', 'description', 'line_type', 'amount']) as $l) {
                    if (in_array(strtolower((string) $l->line_type), self::CARE_TYPES, true)) continue;
                    $i = $byId[$l->invoice_id];
                    $fees[] = ['family' => (string) ($fam[$i->family_id] ?? ''), 'invoice' => (string) $i->invoice_number,
                        'description' => (string) $l->description, 'type' => (string) $l->line_type, 'amount' => round((float) $l->amount, 2)];
                }
            }
        }

        // Closure days this calendar year, on days the centre normally runs.
        $ys = substr($month, 0, 4) . '-01-01';
        $map = Closures::map([$centreId], $ys, $me)[$centreId] ?? [];
        $opDow = [];
        for ($i = 1; $i <= 7; $i++) $opDow[$i] = Closures::isOperatingDay($centreId, Carbon::parse('2024-01-0' . $i)->toDateString());   // Jan 1 2024 was a Monday
        $total = 0; $run = 0; $longest = 0; $longestFrom = null; $runFrom = null; $days = [];
        for ($d = Carbon::parse($ys); $d->toDateString() <= $me; $d->addDay()) {
            if (! $opDow[$d->isoWeekday()]) continue;   // a weekend does not break a run of business days
            $ds = $d->toDateString();
            if (isset($map[$ds])) {
                $total++; $run++;
                if ($run === 1) $runFrom = $ds;
                if ($run > $longest) { $longest = $run; $longestFrom = $runFrom; }
                if ($ds >= $ms) $days[] = ['date' => $ds, 'reason' => Closures::reason($map[$ds])];
            } else {
                $run = 0;
            }
        }
        $closures = ['year_total' => $total, 'longest_run' => $longest, 'longest_from' => $longestFrom, 'this_month' => $days,
            'over_run' => $longest > self::CLOSURE_RUN, 'over_year' => $total > self::CLOSURE_YEAR];
        if ($closures['over_run'] || $closures['over_year']) $issues++;

        return ['set' => $set, 'base' => $bases, 'children' => $children, 'fees' => $fees, 'closures' => $closures,
            'issues' => $issues + count($fees), 'as_of' => $ref, 'centre_cwelcc' => (bool) ($centre->cwelcc_enrolled ?? false)];
    }
}
