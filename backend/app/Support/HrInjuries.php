<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * KIDDIETRAC HR PHASE 3, ITEM 8: STAFF INJURY AND NEAR-MISS RECORDS (2026-11-26).
 *
 * A staff member's own injury (or a near miss), apart from child incidents. One table, hr_injuries, plus check-ins.
 *
 * HEALTH DETAILS are sealed (App\Support\Sensitive, KT_DATA_KEY): what happened, body part, the injury, first aid,
 * medical care and notes, modified duties and check-in notes. LIST and SUMMARY never select them (self::LIST_COLS);
 * they come back only from the reveal endpoint, and every reveal writes hr.injury_health_revealed.
 *
 * WHO SEES A RECORD (one rule, reader and guard together, fail closed, inside the active agency):
 *   - the injured person: their own;
 *   - a centre director: records at their own centres, or about the people placed at them (CentreScope);
 *   - an agency admin (or platform admin working in the agency): all of the agency's.
 * Nobody else: not a file manager, not other staff.
 *
 * THE BOARD REPORT COUNTDOWN (every agency, Anthony 2026-11-26): due within 3 business days of the employer learning of
 * an injury that needed health care beyond first aid or cost time off. Ontario calls it WSIB Form 7; elsewhere the UI
 * says "injury report to your workers' compensation board". Business days = Monday to Friday, less the days the agency
 * recorded as closures (closure_type stat_holiday / holiday / emergency / other, the same set HrLeave::closedDays uses)
 * at that centre, or at any of the agency's centres when the record has none. Counted in the agency's own calendar
 * (learned_on is an agency date; "today" is AgencyTime::today).
 */
final class HrInjuries
{
    public const KINDS = ['injury' => 'Injury', 'near_miss' => 'Near miss'];
    public const CARE = ['none' => 'No', 'doctor' => 'Doctor or clinic', 'hospital' => 'Hospital'];
    public const SEALED = ['what_happened', 'body_part', 'injury_nature', 'first_aid', 'medical_care', 'medical_notes', 'rtw_duties'];
    public const LIST_COLS = ['id', 'agency_id', 'centre_id', 'user_id', 'reported_by_id', 'kind', 'occurred_at', 'learned_on', 'location',
        'witnesses', 'lost_time', 'form7_needed', 'form7_filed_on', 'form7_reference', 'rtw_start', 'rtw_end', 'status', 'created_at', 'updated_at'];
    /** Closure types that make a day a non-business day for the countdown (staff_leave / pd_day do not). */
    public const CLOSED_TYPES = ['stat_holiday', 'holiday', 'emergency', 'other'];
    public const WSIB_URL = 'https://www.wsib.ca/en/businesses/claims/report-injury-or-illness';

    /** WSIB applies (Ontario agencies). */
    public static function isOntario(int $agencyId): bool
    {
        $a = DB::table('agencies')->where('id', $agencyId)->first(['province', 'country']);
        return $a && strtoupper((string) $a->province) === 'ON';
    }

    /** Closures (stat holidays and other closed days) the agency recorded as closures (that centre, or any of its centres), as [Y-m-d => true]. */
    public static function holidays(int $agencyId, ?int $centreId, string $from, string $to): array
    {
        $centres = $centreId ? [$centreId] : Live::centreIds($agencyId);
        $out = [];
        foreach (DB::table('centre_closures')->whereIn('centre_id', $centres ?: [0])->whereIn('closure_type', self::CLOSED_TYPES)
            ->whereDate('closure_date', '<=', $to)
            ->where(fn ($w) => $w->whereDate('closure_date', '>=', $from)->orWhereDate('end_date', '>=', $from))
            ->get(['closure_date', 'end_date']) as $c) {
            $d = Carbon::parse($c->closure_date); $e = $c->end_date ? Carbon::parse($c->end_date) : $d->copy();
            for (; $d->lte($e); $d->addDay()) $out[$d->toDateString()] = true;
        }
        return $out;
    }

    private static function isBusinessDay(Carbon $d, array $holidays): bool
    {
        return ! $d->isWeekend() && ! isset($holidays[$d->toDateString()]);
    }

    /** The Form 7 due date: the 3rd business day after the employer learned of it. */
    public static function form7Due(int $agencyId, ?int $centreId, string $learnedOn, int $days = 3): string
    {
        $start = Carbon::parse($learnedOn)->startOfDay();
        $hol = self::holidays($agencyId, $centreId, $start->toDateString(), $start->copy()->addDays(30)->toDateString());
        $d = $start->copy(); $n = 0;
        while ($n < $days) {
            $d->addDay();
            if (self::isBusinessDay($d, $hol)) $n++;
        }
        return $d->toDateString();
    }

    /** Business days from the agency's today to the due date (0 = due today, negative = days overdue). */
    public static function businessDaysLeft(int $agencyId, ?int $centreId, string $due): int
    {
        $today = Carbon::parse(AgencyTime::today($agencyId));
        $due = Carbon::parse($due);
        if ($today->equalTo($due)) return 0;
        $sign = $today->lt($due) ? 1 : -1;
        [$a, $b] = $sign > 0 ? [$today, $due] : [$due, $today];
        $hol = self::holidays($agencyId, $centreId, $a->toDateString(), $b->toDateString());
        $n = 0;
        for ($d = $a->copy()->addDay(); $d->lte($b); $d->addDay()) if (self::isBusinessDay($d, $hol)) $n++;
        return $sign * $n;
    }

    /** The row shape every list and summary uses: no health details. */
    public static function summary(object $r, array $names, array $centres, bool $ontario): array
    {
        $out = ['id' => (int) $r->id, 'user_id' => (int) $r->user_id, 'person' => $names[(int) $r->user_id] ?? '',
            'reported_by' => $r->reported_by_id ? ($names[(int) $r->reported_by_id] ?? '') : '', 'kind' => $r->kind,
            'occurred_at' => (string) $r->occurred_at, 'learned_on' => substr((string) $r->learned_on, 0, 10), 'location' => $r->location,
            'witnesses' => $r->witnesses, 'centre_id' => $r->centre_id ? (int) $r->centre_id : null, 'centre' => $r->centre_id ? ($centres[(int) $r->centre_id] ?? null) : null,
            'lost_time' => (bool) $r->lost_time, 'form7_needed' => (bool) $r->form7_needed, 'form7_filed_on' => $r->form7_filed_on ? substr((string) $r->form7_filed_on, 0, 10) : null,
            'form7_reference' => $r->form7_reference, 'rtw_start' => $r->rtw_start ? substr((string) $r->rtw_start, 0, 10) : null,
            'rtw_end' => $r->rtw_end ? substr((string) $r->rtw_end, 0, 10) : null, 'status' => $r->status, 'ontario' => $ontario];
        if ($r->kind === 'injury' && $r->form7_needed && ! $r->form7_filed_on) {
            $due = self::form7Due((int) $r->agency_id, $r->centre_id ? (int) $r->centre_id : null, $out['learned_on']);
            $out['form7_due'] = $due;
            $out['form7_days_left'] = self::businessDaysLeft((int) $r->agency_id, $r->centre_id ? (int) $r->centre_id : null, $due);
        }
        return $out;
    }
}
