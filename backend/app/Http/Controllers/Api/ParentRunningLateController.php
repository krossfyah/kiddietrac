<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\SafeArrival;
use App\Support\SafeDeparture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "We're running late" from a parent (2026-11-06, Anthony: "the late function should apply for parents dropping and
 * picking up late to supress the safe arrival alerts and notify the educator about the situation").
 *
 * A parent tells the centre their child will be dropped off (arrival) or picked up (departure) by a time. Until that
 * time plus the grace, Safe Arrival / the end-of-day check does not ask, alert or call anyone. Staff are told
 * (bell + push) by the agency's choice: room educators, directors, admins (Safe Arrival settings, late_notify_*).
 * The notice itself is the same row staff log on the board (safe_arrival_late, source = parent): SafeArrival::logLate.
 *
 * Only the child's own guardians (guardians.user_id): a person check, not an agency one.
 */
class ParentRunningLateController extends Controller
{
    /** GET /parent/running-late - my children today: where each is, and any late notice at either end. */
    public function index(Request $request): JsonResponse
    {
        $kids = $this->myChildren((int) $request->user()->id);
        $out = [];
        $lists = [];   // agency id => [arrival rows, end-of-day rows] (whole agency: today's room can be at another centre)
        foreach ($kids as $k) {
            $agencyId = (int) $k->agency_id;
            $tz = AgencyTime::tz($agencyId);
            $date = AgencyTime::today($agencyId);
            $lists[$agencyId] ??= [SafeArrival::today($agencyId), SafeDeparture::today($agencyId)];
            $arr = $lists[$agencyId][0][(int) $k->id] ?? null;
            $dep = $lists[$agencyId][1][(int) $k->id] ?? null;
            $status = $dep && $dep['still_in'] ? 'in'
                : ($arr ? (($arr['state'] ?? null) === 'absent' ? 'absent' : (($arr['state'] ?? null) === 'arrived' ? 'out' : 'expected')) : 'none');
            $late = [];
            foreach (['arrival', 'departure'] as $kind) {
                $l = SafeArrival::lateQuery([(int) $k->id], $date, $kind)->first();
                $late[$kind] = $l ? ['by' => Carbon::parse($date . ' ' . substr((string) $l->arrive_by, 0, 5), $tz)->format('g:i A'),
                    'by_hm' => substr((string) $l->arrive_by, 0, 5), 'note' => $l->note,
                    'mine' => (int) $l->reported_by_id === (int) $request->user()->id] : null;
            }
            $out[] = [
                'child_id' => (int) $k->id,
                'name' => trim(($k->preferred_name ?: $k->first_name) . ' ' . $k->last_name),
                'first_name' => $k->preferred_name ?: $k->first_name,
                // A home named after its provider reads "Amna's home child care": an educator to a family is a first name (2026-11-27).
                'centre_name' => \App\Support\FamilyFacingName::place((int) $k->centre_id ?: null, (string) $k->centre_name),
                // in = at the centre (pickup can be late); expected = not here yet (drop-off can be late)
                'status' => $status,
                'can_arrival' => $status === 'expected',
                'can_departure' => in_array($status, ['in', 'expected'], true),
                'expected_in' => $arr && ! empty($arr['expected']) ? Carbon::parse($arr['expected'], $tz)->format('g:i A') : null,
                'expected_out' => $dep ? Carbon::parse($dep['expected'], $tz)->format('g:i A') : null,
                'late' => $late,
            ];
        }

        return response()->json(['children' => $out]);
    }

    /** POST /parent/running-late {child_id, kind: arrival|departure, time: "HH:MM", note?} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'kind' => ['required', 'in:arrival,departure'],
            'time' => ['required', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $k = $this->myChildren((int) $request->user()->id, (int) $data['child_id'])->first();
        if (! $k) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        [$err] = SafeArrival::logLate((int) $k->agency_id, (int) $k->id, $data['kind'], $data['time'], $data['note'] ?? null,
            (int) $request->user()->id, 'parent');
        if ($err) {
            return response()->json(['message' => $err === 'That child is not on today\'s list.'
                ? ($k->preferred_name ?: $k->first_name) . ' is not expected at the centre today.' : $err], 422);
        }

        return $this->index($request);
    }

    /** DELETE /parent/running-late/{child}/{kind} - "never mind, we're on time" */
    public function destroy(Request $request, int $child, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, ['arrival', 'departure'], true), 404);
        $k = $this->myChildren((int) $request->user()->id, $child)->first();
        if (! $k) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        SafeArrival::cancelLate((int) $k->agency_id, (int) $k->id, $kind, (int) $request->user()->id, 'parent');

        return $this->index($request);
    }

    /** The children this person is a guardian of (open families, open centres). */
    private function myChildren(int $userId, ?int $childId = null)
    {
        return DB::table('guardians as g')
            ->join('children as c', 'c.family_id', '=', 'g.family_id')
            ->join('families as fa', 'fa.id', '=', 'c.family_id')
            ->join('centres as ce', 'ce.id', '=', 'fa.centre_id')
            ->tap(fn ($q) => \App\Support\Live::centres(\App\Support\Live::families($q, 'fa'), 'ce'))
            ->where('g.user_id', $userId)
            ->whereNull('c.deleted_at')
            ->when($childId !== null, fn ($q) => $q->where('c.id', $childId))
            ->orderBy('c.first_name')
            ->distinct()
            ->get(['c.id', 'c.first_name', 'c.preferred_name', 'c.last_name', 'ce.id as centre_id', 'ce.name as centre_name', 'ce.agency_id']);
    }
}
