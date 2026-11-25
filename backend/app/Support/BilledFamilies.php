<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHO HAS BEEN BILLED (2026-11-25, Anthony: "kiddietrac says no invoices raised for parents but the invoices are
 * raised through ilearn each cycle - is the system not recognizing this?").
 *
 * A family is billed by EITHER system: an invoice raised here (`invoices`) or one raised in the partner's own
 * billing and synced in (`external_invoices`, e.g. iLearn). The admin digest counted only `invoices`, and only those
 * CREATED inside the digest window (today, for the daily one) - so every family was "unbilled" every morning, and
 * the advice was a bulk run that would have billed iLearn's families a second time. The bulk run's own "already
 * invoiced" check had the same blind spot (it looked at issued_at only, so a December invoice issued in November
 * by a schedule did not stop a December run either).
 *
 * The one answer both read, so the warning and the guard cannot disagree (reader and guard move together).
 */
class BilledFamilies
{
    /** Statuses that mean "this invoice does not stand". */
    public const DEAD = ['void', 'voided', 'cancelled', 'canceled'];

    /**
     * Of $familyIds, those already billed for the calendar month [$start, $end] (Y-m-d).
     *
     * Raised here: issued in the month, or its billing period overlaps the month. $countVoidNative keeps the bulk
     * run's long-standing rule that a voided invoice still blocks a re-run (void and re-raise is done by hand).
     * Partner: a standing (not void) invoice due in the month, or issued in it.
     *
     * @return array{native: int[], partner: int[]}
     */
    public static function forMonth(array $familyIds, string $start, string $end, bool $countVoidNative = false): array
    {
        $familyIds = array_values(array_unique(array_map('intval', $familyIds)));
        if (! $familyIds) {
            return ['native' => [], 'partner' => []];
        }
        $native = DB::table('invoices')->whereIn('family_id', $familyIds)
            ->when(! $countVoidNative, fn ($q) => $q->whereNotIn(DB::raw('LOWER(status)'), self::DEAD))
            ->where(fn ($q) => $q->whereBetween(DB::raw('DATE(issued_at)'), [$start, $end])
                ->orWhere(fn ($p) => $p->whereDate('period_start', '<=', $end)->whereDate('period_end', '>=', $start)))
            ->distinct()->pluck('family_id')->map(fn ($v) => (int) $v)->all();
        $partner = [];
        if (Schema::hasTable('external_invoices')) {
            $partner = DB::table('external_invoices')->whereIn('family_id', $familyIds)
                ->whereNotIn(DB::raw('LOWER(status)'), self::DEAD)
                ->where(fn ($q) => $q->whereBetween(DB::raw('DATE(due_at)'), [$start, $end])
                    ->orWhereBetween(DB::raw('DATE(issued_at)'), [$start, $end]))
                ->distinct()->pluck('family_id')->map(fn ($v) => (int) $v)->all();
        }

        return ['native' => $native, 'partner' => $partner];
    }

    /**
     * Families at $centreIds with an enrolled child and NOTHING billed from $since (Y-m-d, the start of the current
     * cycle) onward, in either system. An invoice raised ahead (a schedule's December instalment) counts: that
     * family is billed. Returns [id => family_name], plus which billing each family has used before.
     *
     * @return array{families: array<int,string>, partner_agency: ?string}  partner_agency = the partner's name ("iLearn") when these families are billed there too
     */
    public static function unbilledSince(array $centreIds, string $since): array
    {
        $fams = DB::table('families as f')->whereIn('f.centre_id', $centreIds ?: [0])
            ->whereNull('f.deleted_at')->whereNull('f.suspended_at')
            ->whereExists(fn ($q) => $q->from('children as ch')->whereColumn('ch.family_id', 'f.id')
                ->whereNull('ch.deleted_at')->where('ch.enrollment_status', 'enrolled'))
            ->orderBy('f.family_name')
            ->pluck('f.family_name', 'f.id')->all();
        if (! $fams) {
            return ['families' => [], 'partner_agency' => null];
        }
        $ids = array_keys($fams);
        $billed = DB::table('invoices')->whereIn('family_id', $ids)
            ->whereNotIn(DB::raw('LOWER(status)'), self::DEAD)
            ->where(fn ($q) => $q->whereDate('period_end', '>=', $since)->orWhereDate('due_at', '>=', $since)
                ->orWhereDate('issued_at', '>=', $since))
            ->distinct()->pluck('family_id')->map(fn ($v) => (int) $v)->all();
        $partnerAgency = null;
        if (Schema::hasTable('external_invoices')) {
            $billed = array_merge($billed, DB::table('external_invoices')->whereIn('family_id', $ids)
                ->whereNotIn(DB::raw('LOWER(status)'), self::DEAD)
                ->where(fn ($q) => $q->whereDate('due_at', '>=', $since)->orWhereDate('issued_at', '>=', $since))
                ->distinct()->pluck('family_id')->map(fn ($v) => (int) $v)->all());
            $src = DB::table('external_invoices')->whereIn('family_id', $ids)->orderByDesc('id')
                ->first(['source_label', 'external_source']);
            if ($src) {
                $lbl = trim((string) ($src->source_label ?? ''));
                $partnerAgency = $lbl !== '' && strlen($lbl) <= 30 ? $lbl
                    : (strtolower((string) $src->external_source) === 'ilearn' ? 'iLearn' : 'your billing system');
            }
        }
        $out = array_diff_key($fams, array_flip($billed));

        return ['families' => $out, 'partner_agency' => $partnerAgency];
    }
}
