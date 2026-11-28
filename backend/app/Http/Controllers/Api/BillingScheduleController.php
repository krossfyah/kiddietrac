<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\BillingSchedules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * v22p58 — Weekly, biweekly or monthly billing schedule per family.
 * Since 2026-11-28 the schedule RUNS: billing:schedules (App\Support\BillingSchedules) raises the invoice on each
 * date in the agency's timezone and charges autopay families through AutopayRunner. next_charge_at is the next
 * invoice date (agency local date at midnight).
 */
final class BillingScheduleController extends Controller
{
    use ResolvesCentreContext;

    public function get(Request $request, int $familyId): JsonResponse
    {
        $this->assertAccess($request, $familyId);
        $row = DB::table('billing_schedules')->where('family_id', $familyId)->first();
        $runs = DB::table('billing_schedule_runs as r')->leftJoin('invoices as i', 'i.id', '=', 'r.invoice_id')
            ->where('r.family_id', $familyId)->orderByDesc('r.run_on')->orderByDesc('r.id')->limit(12)
            ->get(['r.run_on', 'r.period_start', 'r.period_end', 'r.status', 'r.amount', 'r.message', 'r.invoice_id', 'i.invoice_number']);
        $agencyId = $this->familyAgency($familyId);
        $next = null;
        if ($row && (int) $row->active && $row->auto_since) {
            $next = $row->next_charge_at ? substr((string) $row->next_charge_at, 0, 10) : BillingSchedules::nextDate($row, AgencyTime::today($agencyId));
        }
        $period = $row && $next ? BillingSchedules::periodFor($row, $next) : null;
        return response()->json(['data' => $row, 'next_invoice' => $next, 'next_period' => $period ? ['start' => $period[0], 'end' => $period[1]] : null,
            'running' => (bool) ($row && (int) $row->active && $row->auto_since), 'runs' => $runs, 'today' => AgencyTime::today($agencyId)]);
    }

    /** What the next invoice date would be for these settings, without saving (the form's live "Next invoice"). */
    public function preview(Request $request): JsonResponse
    {
        $d = $this->input($request);
        $this->assertAccess($request, (int) $d['family_id'], true);
        $today = AgencyTime::today($this->familyAgency((int) $d['family_id']));
        $s = $this->shape($d, $today);
        $next = BillingSchedules::nextDate($s, $today);
        $p = BillingSchedules::periodFor($s, $next);
        return response()->json(['next_invoice' => $next, 'next_period' => ['start' => $p[0], 'end' => $p[1]]]);
    }

    public function set(Request $request): JsonResponse
    {
        $d = $this->input($request);
        $fid = (int) $d['family_id'];
        $this->assertAccess($request, $fid, true);
        $today = AgencyTime::today($this->familyAgency($fid));
        $s = $this->shape($d, $today);
        // Today is fine as the first date: the hourly job bills it today. Never earlier, so nothing back-dated runs.
        $next = BillingSchedules::nextDate($s, $today);
        $old = DB::table('billing_schedules')->where('family_id', $fid)->first();
        DB::table('billing_schedules')->updateOrInsert(
            ['family_id' => $fid],
            [
                'frequency' => $s->frequency,
                'day_of_week' => $s->day_of_week,
                'day_of_month' => $s->day_of_month,
                'anchor_date' => $s->anchor_date,
                'next_charge_at' => $next . ' 00:00:00',
                'active' => 1,
                'auto_since' => ($old && (int) $old->active && $old->auto_since) ? $old->auto_since : now(),
                'updated_by_id' => $request->user()?->id,
                'created_at' => $old->created_at ?? now(),
                'updated_at' => now(),
            ]
        );
        \App\Support\Audit::write(['user_id' => $request->user()?->id, 'agency_id' => $this->familyAgency($fid), 'action' => 'billing_schedule.saved',
            'entity_type' => 'family', 'entity_id' => $fid,
            'payload' => json_encode(['frequency' => $s->frequency, 'day_of_week' => $s->day_of_week, 'day_of_month' => $s->day_of_month, 'next_invoice' => $next]),
            'created_at' => now()]);
        return response()->json(['next_charge_at' => $next, 'next_invoice' => $next]);
    }

    public function disable(Request $request, int $familyId): JsonResponse
    {
        $this->assertAccess($request, $familyId, true);
        DB::table('billing_schedules')->where('family_id', $familyId)->update([
            'active' => 0, 'auto_since' => null, 'updated_by_id' => $request->user()?->id, 'updated_at' => now(),
        ]);
        \App\Support\Audit::write(['user_id' => $request->user()?->id, 'agency_id' => $this->familyAgency($familyId), 'action' => 'billing_schedule.disabled',
            'entity_type' => 'family', 'entity_id' => $familyId, 'payload' => null, 'created_at' => now()]);
        return response()->json(['status' => 'disabled']);
    }

    private function input(Request $request): array
    {
        $v = Validator::make($request->all(), [
            'family_id' => 'required|integer',
            'frequency' => 'required|in:weekly,biweekly,monthly',
            'day_of_week' => 'nullable|required_unless:frequency,monthly|integer|between:0,6',
            'day_of_month' => 'nullable|required_if:frequency,monthly|integer|between:1,31',
            'anchor_date' => 'nullable|date',
        ], [
            'frequency.in' => 'Choose weekly, every two weeks or monthly.',
            'day_of_week.required_unless' => 'Choose the day of the week to bill on.',
            'day_of_week.between' => 'Choose a day of the week.',
            'day_of_month.required_if' => 'Choose the day of the month to bill on.',
            'day_of_month.between' => 'The day of the month must be from 1 to 31.',
        ]);
        return $v->validate();
    }

    /** The schedule as stored. A biweekly schedule keeps its anchor (its first date) so "every second week" is fixed. */
    private function shape(array $d, string $today): object
    {
        $s = (object) ['frequency' => $d['frequency'], 'day_of_week' => null, 'day_of_month' => null, 'anchor_date' => null];
        if ($d['frequency'] === 'monthly') {
            $s->day_of_month = (int) $d['day_of_month'];
        } else {
            $s->day_of_week = (int) $d['day_of_week'];
        }
        if ($d['frequency'] === 'biweekly') {
            $old = DB::table('billing_schedules')->where('family_id', (int) $d['family_id'])->first();
            $anchor = ! empty($d['anchor_date']) ? substr((string) $d['anchor_date'], 0, 10)
                : (($old && $old->frequency === 'biweekly' && (int) $old->day_of_week === $s->day_of_week && $old->anchor_date) ? substr((string) $old->anchor_date, 0, 10) : null);
            $s->anchor_date = BillingSchedules::nextDate((object) ['frequency' => 'weekly', 'day_of_week' => $s->day_of_week], $anchor && $anchor >= $today ? $anchor : $today);
            if ($anchor && $anchor < $today && \Illuminate\Support\Carbon::parse($anchor)->dayOfWeek === $s->day_of_week) {
                $s->anchor_date = $anchor;
            }
        }
        return $s;
    }

    private function familyAgency(int $familyId): ?int
    {
        $a = DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')->where('f.id', $familyId)->value('c.agency_id');
        return $a ? (int) $a : null;
    }

    private function assertAccess(Request $request, int $familyId, bool $write = false): void
    {
        // SECURITY (v22p96): a guardian of THIS family, staff of its centre, or a platform_admin scoped to its agency.
        abort_unless($this->canAccessFamilyScoped($request, $familyId), 403);
        // Saving or stopping a schedule raises (and may charge) real invoices since 2026-11-28: the office only.
        if ($write) {
            abort_unless(array_intersect(\App\Support\AgencyRole::names($request), ['agency_admin', 'centre_director', 'platform_admin']), 403,
                'Only the office can change a billing schedule.');
        }
    }
}
