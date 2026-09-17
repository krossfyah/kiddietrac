<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WHICH DOSES A CHILD OWES, AND WHEN THEY WERE DUE.
 *
 * This lived inside ImmunizationScheduleController::childStatus() — a controller method
 * that aborts 403 unless the CALLER may see the child. Exactly right for an endpoint and
 * useless to a cron job, which has no caller at all. Rather than write the rules a second
 * time for the reminder (and let the two drift the first time an agency changes its
 * schedule), the computation moved here and the endpoint calls it.
 *
 * The rules are unchanged, deliberately, so the screen keeps saying what it said:
 *
 *   • a dose is matched to a record by `vaccine|dose_label`, case- and space-insensitive;
 *   • a matched record is `done`, or `exempt` when the exemption flag is set;
 *   • otherwise it is `overdue` once the due date has passed, `due_soon` inside the lead
 *     window, and `pending` beyond it;
 *   • the due date is the child's date of birth plus the schedule's age in months.
 *
 * The one thing that IS new: the lead window is a parameter. The screen has always used
 * two months; a parent reminder wants to be told how far ahead its agency cares about.
 *
 * NOT here, on purpose: whether a card has been FILED. A filed document is not a recorded
 * dose — see the note in childStatus() — and nothing in this class should ever be made to
 * clear a compliance flag because a PDF arrived.
 */
final class ImmunizationDue
{
    /**
     * @param  int   $leadMonths   how far ahead counts as "due soon"
     * @param  bool  $requiredOnly only doses the schedule marks as required
     * @return array{
     *     child_id:int, child_name:string, age_months:int,
     *     overdue:array<int,array<string,mixed>>,
     *     due_soon:array<int,array<string,mixed>>,
     *     items:array<int,array<string,mixed>>
     * }|null  null when the child or their date of birth is unusable
     */
    public static function forChild(int $childId, int $leadMonths = 2, bool $requiredOnly = false): ?array
    {
        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();
        if (! $child || ! $child->date_of_birth) {
            return null;
        }

        $agencyId = self::agencyOfChild($child);
        if (! $agencyId) {
            return null;
        }

        return self::compute($child, self::scheduleFor($agencyId), $leadMonths, $requiredOnly);
    }

    /**
     * Every enrolled child in an agency who owes something, newest problem first.
     *
     * One query for the schedule and one for the whole agency's doses — the report this
     * replaces called childStatus() once per child, which is two queries per child plus a
     * JSON round trip, and an agency with 300 children felt it.
     *
     * @return array<int,array<string,mixed>>  keyed by nothing; each row carries child_id
     */
    public static function forAgency(int $agencyId, int $leadMonths = 2, bool $requiredOnly = false): array
    {
        $schedule = self::scheduleFor($agencyId);
        if (! $schedule) {
            return [];
        }

        $children = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('c.agency_id', $agencyId)
            ->whereNull('ch.deleted_at')
            ->where('ch.enrollment_status', 'enrolled')
            ->whereNotNull('ch.date_of_birth')
            ->get(['ch.id', 'ch.first_name', 'ch.last_name', 'ch.preferred_name',
                'ch.date_of_birth', 'ch.family_id', 'f.family_name', 'c.name as centre_name']);

        if ($children->isEmpty()) {
            return [];
        }

        $doses = DB::table('immunizations')
            ->whereIn('child_id', $children->pluck('id')->all())
            ->get(['child_id', 'vaccine', 'dose_label', 'administered_on', 'exempt'])
            ->groupBy('child_id');

        $out = [];
        foreach ($children as $ch) {
            $row = self::compute($ch, $schedule, $leadMonths, $requiredOnly, $doses->get($ch->id));
            if (! $row) {
                continue;
            }
            if (! $row['overdue'] && ! $row['due_soon']) {
                continue;               // nothing to chase — say nothing
            }
            $row['family_id'] = (int) ($ch->family_id ?? 0);
            $row['family_name'] = $ch->family_name ?? '';
            $row['centre_name'] = $ch->centre_name ?? '';
            $out[] = $row;
        }

        // Most overdue first: whoever reads this should see the worst case at the top.
        usort($out, fn ($a, $b) => count($b['overdue']) <=> count($a['overdue']));

        return $out;
    }

    /** The agency's active schedule, in due order. */
    public static function scheduleFor(int $agencyId): array
    {
        return DB::table('immunization_schedule')
            ->where('agency_id', $agencyId)
            ->where('active', 1)
            ->orderBy('due_at_age_months')
            ->get(['vaccine', 'dose_label', 'due_at_age_months', 'is_required'])
            ->all();
    }

    private static function agencyOfChild(object $child): ?int
    {
        $centreId = DB::table('families')->where('id', $child->family_id ?? 0)->value('centre_id');

        return $centreId ? ((int) DB::table('centres')->where('id', $centreId)->value('agency_id') ?: null) : null;
    }

    /**
     * @param  array<int,object>  $schedule
     * @param  \Illuminate\Support\Collection<int,object>|null  $doses  pre-loaded, else fetched
     */
    private static function compute(object $child, array $schedule, int $leadMonths, bool $requiredOnly, $doses = null): ?array
    {
        try {
            $dob = Carbon::parse($child->date_of_birth);
        } catch (\Throwable $e) {
            return null;
        }

        if ($doses === null) {
            $doses = DB::table('immunizations')->where('child_id', $child->id)
                ->get(['vaccine', 'dose_label', 'administered_on', 'exempt']);
        }

        $key = fn ($v, $d) => strtolower(trim(((string) $v) . '|' . ((string) $d)));
        $byKey = [];
        foreach ($doses as $r) {
            $byKey[$key($r->vaccine, $r->dose_label)] = $r;
        }

        $today = Carbon::now()->startOfDay();
        $ageMonths = (int) $dob->diffInMonths(Carbon::now());

        $items = [];
        $overdue = [];
        $dueSoon = [];

        foreach ($schedule as $s) {
            if ($requiredOnly && ! $s->is_required) {
                continue;
            }
            $rec = $byKey[$key($s->vaccine, $s->dose_label)] ?? null;
            $dueAt = $dob->copy()->addMonths((int) $s->due_at_age_months);
            $monthsUntil = (int) $today->diffInMonths($dueAt, false);

            $status = 'pending';
            if ($rec) {
                $status = $rec->exempt ? 'exempt' : 'done';
            } elseif ($monthsUntil < 0) {
                $status = 'overdue';
            } elseif ($monthsUntil < $leadMonths) {
                $status = 'due_soon';
            }

            $item = [
                'vaccine' => $s->vaccine,
                'dose_label' => $s->dose_label,
                'due_at_age_months' => (int) $s->due_at_age_months,
                'due_date' => $dueAt->toDateString(),
                'months_until_due' => $monthsUntil,
                'status' => $status,
                'administered_on' => $rec->administered_on ?? null,
                'is_required' => (bool) $s->is_required,
            ];
            $items[] = $item;

            if ($status === 'overdue') {
                $overdue[] = $item;
            } elseif ($status === 'due_soon') {
                $dueSoon[] = $item;
            }
        }

        return [
            'child_id' => (int) $child->id,
            'child_name' => trim((($child->preferred_name ?? '') ?: $child->first_name) . ' ' . $child->last_name),
            'age_months' => $ageMonths,
            'items' => $items,
            'overdue' => $overdue,
            'due_soon' => $dueSoon,
        ];
    }
}
