<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CWELCC enrolment as dated periods (2026-09-29). See the migration
 * 2026_09_29_200000_subsidy_family_history for why.
 *
 * The ONE writer. families.cwelcc_enrolled / _enrolled_at / _subsidy_rate are kept in step
 * as a mirror of the open period, so screens that read the flag keep working -- but the
 * periods are the record, and the monthly report reads them.
 *
 * A change never rewrites a closed period. Ending closes the open one; a new rate closes
 * the open one the day before it takes effect and opens another. So last spring's claim
 * reads the same next year as it did the day it was filed.
 */
class CwelccHistory
{
    public static function open(int $familyId): ?object
    {
        return DB::table('cwelcc_enrolments')->where('family_id', $familyId)
            ->whereNull('enrolled_to')->orderByDesc('enrolled_from')->first();
    }

    /** Every period, newest first. */
    public static function periods(int $familyId)
    {
        return DB::table('cwelcc_enrolments as e')
            ->leftJoin('users as us', 'us.id', '=', 'e.started_by_id')
            ->leftJoin('users as ue', 'ue.id', '=', 'e.ended_by_id')
            ->where('e.family_id', $familyId)
            ->orderByDesc('e.enrolled_from')
            ->get(['e.*', DB::raw("TRIM(CONCAT(COALESCE(us.first_name,''),' ',COALESCE(us.last_name,''))) as started_by"),
                DB::raw("TRIM(CONCAT(COALESCE(ue.first_name,''),' ',COALESCE(ue.last_name,''))) as ended_by")]);
    }

    /**
     * Enrol from $from at $rate. Refuses if already enrolled, or if $from falls inside an
     * earlier period (history is not overwritten). Returns an error string or null.
     */
    public static function enrol(int $familyId, string $from, ?float $rate, ?int $by): ?string
    {
        if (self::open($familyId)) {
            return 'This family is already enrolled in CWELCC. Change the rate or end the enrolment instead.';
        }
        $clash = DB::table('cwelcc_enrolments')->where('family_id', $familyId)
            ->where('enrolled_from', '<=', $from)->where('enrolled_to', '>=', $from)->first();
        if ($clash) {
            return 'That date falls inside an earlier enrolment (' . $clash->enrolled_from . ' to ' . $clash->enrolled_to . '). Pick a later start date.';
        }
        $centre = DB::table('families')->where('id', $familyId)->value('centre_id');
        DB::table('cwelcc_enrolments')->insert([
            'family_id' => $familyId, 'centre_id' => $centre, 'enrolled_from' => $from, 'enrolled_to' => null,
            'subsidy_rate' => $rate, 'source' => 'recorded', 'started_by_id' => $by,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        self::mirror($familyId);

        return null;
    }

    /** End the open period on $to (its last day). */
    public static function end(int $familyId, string $to, ?int $by): ?string
    {
        $open = self::open($familyId);
        if (! $open) {
            return 'This family is not enrolled in CWELCC.';
        }
        if ($to < $open->enrolled_from) {
            return 'The last day cannot be before the enrolment started (' . $open->enrolled_from . ').';
        }
        DB::table('cwelcc_enrolments')->where('id', $open->id)
            ->update(['enrolled_to' => $to, 'ended_by_id' => $by, 'updated_at' => now()]);
        self::mirror($familyId);

        return null;
    }

    /** A new rate from $from: close the open period the day before, open another. */
    public static function changeRate(int $familyId, string $from, ?float $rate, ?int $by): ?string
    {
        $open = self::open($familyId);
        if (! $open) {
            return 'This family is not enrolled in CWELCC.';
        }
        if ($from <= $open->enrolled_from) {
            // Same start day: nothing has been reported under the old rate yet, so
            // correcting it in place does not rewrite any history.
            if ($from === $open->enrolled_from) {
                DB::table('cwelcc_enrolments')->where('id', $open->id)->update(['subsidy_rate' => $rate, 'updated_at' => now()]);
                self::mirror($familyId);

                return null;
            }

            return 'The new rate must start after the current enrolment began (' . $open->enrolled_from . ').';
        }
        $dayBefore = Carbon::parse($from)->subDay()->toDateString();
        DB::transaction(function () use ($open, $familyId, $from, $rate, $by, $dayBefore) {
            DB::table('cwelcc_enrolments')->where('id', $open->id)
                ->update(['enrolled_to' => $dayBefore, 'ended_by_id' => $by, 'updated_at' => now()]);
            DB::table('cwelcc_enrolments')->insert([
                'family_id' => $familyId, 'centre_id' => $open->centre_id, 'enrolled_from' => $from, 'enrolled_to' => null,
                'subsidy_rate' => $rate, 'source' => 'recorded', 'started_by_id' => $by,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        self::mirror($familyId);

        return null;
    }

    /** families.cwelcc_* = the open period, for the screens that still read the flag. */
    public static function mirror(int $familyId): void
    {
        $open = self::open($familyId);
        DB::table('families')->where('id', $familyId)->update([
            'cwelcc_enrolled' => $open ? 1 : 0,
            'cwelcc_enrolled_at' => $open ? $open->enrolled_from : DB::raw('cwelcc_enrolled_at'),
            'cwelcc_subsidy_rate' => $open ? $open->subsidy_rate : DB::raw('cwelcc_subsidy_rate'),
            'updated_at' => now(),
        ]);
    }
}
