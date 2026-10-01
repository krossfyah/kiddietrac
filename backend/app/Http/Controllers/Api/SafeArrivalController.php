<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\SafeArrival;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Safe Arrival board for staff (2026-09-29). See App\Support\SafeArrival.
 *
 * Scope: an educator sees the rooms they are assigned to, a centre director their
 * centres, an agency admin or platform admin the whole active agency.
 */
class SafeArrivalController extends Controller
{
    /** GET /safe-arrival/today?centre_id= */
    public function today(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        [$centres, $rooms, $role] = $this->scope($request, $agencyId);
        if ($request->filled('centre_id')) {
            $want = (int) $request->query('centre_id');
            $centres = $centres === null ? [$want] : array_values(array_intersect($centres, [$want]));
        }
        $cfg = SafeArrival::settings($agencyId);
        $tz = \App\Support\AgencyTime::tz($agencyId);
        $rows = SafeArrival::today($agencyId, $centres, $rooms);

        $needGuardians = array_values(array_unique(array_map(fn ($r) => $r['family_id'],
            array_filter($rows, fn ($r) => in_array($r['state'], ['overdue', 'escalated'], true)))));
        $guardians = [];
        if ($needGuardians) {
            foreach (DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
                ->whereIn('g.family_id', $needGuardians)->whereNull('u.deleted_at')
                ->get(['g.family_id', 'u.first_name', 'u.last_name', 'u.phone', 'g.relationship']) as $g) {
                $guardians[(int) $g->family_id][] = ['name' => trim($g->first_name . ' ' . $g->last_name),
                    'phone' => $g->phone, 'relationship' => $g->relationship];
            }
        }

        $fmt = fn ($ts) => $ts ? Carbon::parse($ts)->timezone($tz)->format('g:i A') : null;
        $out = array_values(array_map(function ($r) use ($guardians, $fmt, $tz) {
            $c = $r['check'];
            return [
                'child_id' => $r['child_id'], 'name' => $r['name'],
                'room_name' => $r['room_name'], 'centre_id' => $r['centre_id'], 'centre_name' => $r['centre_name'],
                'expected' => Carbon::parse($r['expected'], $tz)->format('g:i A'),
                'expected_is_default' => $r['expected_is_default'],
                'due' => $fmt($r['due_at']),
                'state' => $r['state'],
                'arrived' => $fmt($r['arrived_at']),
                'absent' => $r['absent'],
                'parents_notified' => $c ? $fmt($c->parents_notified_at) : null,
                'escalated' => $c ? $fmt($c->escalated_at) : null,
                'staff_unreached' => $c ? ($c->staff_unreached ?? null) : null,
                'resolution' => $c ? $c->resolution : null,
                'note' => $c ? $c->note : null,
                'resolved' => $c ? $fmt($c->resolved_at) : null,
                'guardians' => $guardians[$r['family_id']] ?? [],
            ];
        }, $rows));

        // Most urgent first: escalated, overdue, then waiting, then settled.
        $rank = ['escalated' => 0, 'overdue' => 1, 'waiting' => 2, 'resolved' => 3, 'absent' => 4, 'arrived' => 5];
        usort($out, fn ($a, $b) => [$rank[$a['state']], $a['name']] <=> [$rank[$b['state']], $b['name']]);
        $counts = array_count_values(array_column($out, 'state'));

        $centreList = DB::table('centres')->where('agency_id', $agencyId)
            ->when($centres !== null && ! $request->filled('centre_id'), fn ($q) => $q->whereIn('id', $centres ?: [0]))
            ->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'date' => \App\Support\AgencyTime::today($agencyId),
            'settings' => $cfg,
            'can_configure' => $role === 'admin',
            'counts' => ['expected' => count($out)] + $counts,
            'rows' => $out,
            'centres' => $centreList,
        ]);
    }

    /** POST /safe-arrival/{childId}/resolve {resolution: absent|parent_contacted|other, note?, reason?} */
    public function resolve(Request $request, int $childId): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        [$centres, $rooms] = $this->scope($request, $agencyId);
        $data = $request->validate([
            'resolution' => ['required', 'in:absent,parent_contacted,other'],
            'note' => ['nullable', 'string', 'max:1000'],
            // The same reasons a parent's "Not attending today" offers.
            'reason' => ['nullable', 'in:sick,appointment,holiday,family,other'],
        ]);
        if ($data['resolution'] !== 'absent' && empty(trim((string) ($data['note'] ?? '')))) {
            return response()->json(['message' => 'Add a short note saying what happened, e.g. "Spoke to mum - on the way".',
                'errors' => ['note' => ['Required.']]], 422);
        }
        $rows = SafeArrival::today($agencyId, $centres, $rooms);
        $row = $rows[$childId] ?? null;
        abort_unless($row, 404, 'That child is not on today\'s Safe Arrival list.');
        $date = \App\Support\AgencyTime::today($agencyId);
        $uid = (int) $request->user()->id;

        if ($data['resolution'] === 'absent') {
            // A real absence, the same record a parent's "Not attending today" makes, so
            // the reminders, the roster and the parent all see it.
            DB::table('child_absences')->updateOrInsert(
                ['child_id' => $childId, 'absent_on' => $date],
                ['reason' => $data['reason'] ?: 'other', 'note' => trim('Recorded by staff (Safe Arrival). ' . ($data['note'] ?? '')),
                    'reported_by_id' => $uid, 'created_at' => now()]
            );
        }
        $vals = [
            'status' => $data['resolution'] === 'absent' ? 'absent' : 'resolved',
            'resolution' => $data['resolution'], 'note' => $data['note'] ?? null,
            'resolved_at' => now(), 'resolved_by_id' => $uid, 'updated_at' => now(),
        ];
        $existing = DB::table('safe_arrival_checks')->where('child_id', $childId)->where('check_date', $date)->first();
        if ($existing) {
            DB::table('safe_arrival_checks')->where('id', $existing->id)->update($vals);
        } else {
            DB::table('safe_arrival_checks')->insert($vals + [
                'agency_id' => $agencyId, 'centre_id' => $row['centre_id'], 'room_id' => $row['room_id'],
                'child_id' => $childId, 'check_date' => $date, 'expected_time' => $row['expected'] . ':00',
                'due_at' => $row['due_at'], 'created_at' => now(),
            ]);
        }
        try {
            \App\Support\Audit::write([
                'user_id' => $uid, 'agency_id' => $agencyId, 'action' => 'safe_arrival.resolved',
                'entity_type' => 'child', 'entity_id' => $childId,
                'payload' => json_encode([
                    'summary' => 'Safe Arrival for ' . $row['name'] . ': ' . str_replace('_', ' ', $data['resolution'])
                        . (! empty($data['note']) ? ' - ' . $data['note'] : ''),
                    'child' => $row['name'], 'date' => $date, 'resolution' => $data['resolution'],
                    'reason' => $data['reason'] ?? null, 'note' => $data['note'] ?? null,
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }

        return $this->today($request);
    }

    /** GET /safe-arrival/settings */
    public function settings(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->scope($request, $agencyId);

        return response()->json(['settings' => SafeArrival::settings($agencyId)]);
    }

    /** PUT /safe-arrival/settings -- agency admins only. */
    public function saveSettings(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        [, , $role] = $this->scope($request, $agencyId);
        abort_unless($role === 'admin', 403, 'Only an agency admin can change Safe Arrival settings.');
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:180'],
            'default_time' => ['sometimes', 'regex:/^\d{2}:\d{2}$/'],
            'escalate_after_minutes' => ['sometimes', 'integer', 'min:5', 'max:240'],
        ]);
        $before = SafeArrival::settings($agencyId);
        $after = SafeArrival::saveSettings($agencyId, $data);
        try {
            \App\Support\Audit::write([
                'user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => 'safe_arrival.settings',
                'entity_type' => 'agency', 'entity_id' => $agencyId,
                'payload' => json_encode(['summary' => 'Safe Arrival ' . ($after['enabled'] ? 'on' : 'off')
                    . ': ' . $after['grace_minutes'] . ' min grace, escalate after ' . $after['escalate_after_minutes']
                    . ' min, default time ' . $after['default_time'], 'before' => $before, 'after' => $after]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }

        return response()->json(['settings' => $after]);
    }

    /** [centreIds|null, roomIds|null, 'admin'|'director'|'educator'] */
    private function scope(Request $request, int $agencyId): array
    {
        $uid = (int) $request->user()->id;
        $ra = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)->get(['role', 'agency_id', 'centre_id']);
        if ($ra->contains(fn ($r) => $r->role === 'platform_admin'
            || ($r->role === 'agency_admin' && (int) $r->agency_id === $agencyId))) {
            return [null, null, 'admin'];
        }
        $centres = $ra->filter(fn ($r) => $r->role === 'centre_director' && (int) $r->agency_id === $agencyId && $r->centre_id)
            ->pluck('centre_id')->map(fn ($x) => (int) $x)->unique()->values()->all();
        if ($centres) {
            return [$centres, null, 'director'];
        }
        if ($ra->contains(fn ($r) => $r->role === 'educator' && (int) $r->agency_id === $agencyId)) {
            $rooms = DB::table('educator_rooms')->where('user_id', $uid)->pluck('room_id')->map(fn ($x) => (int) $x)->all();

            return [null, $rooms ?: [0], 'educator'];
        }
        abort(403, 'Safe Arrival is for centre staff.');
    }

    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($activeId && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
                ->where(function ($q) use ($activeId) { $q->where('role', 'platform_admin')->orWhere('agency_id', $activeId); })->exists()) {
            return $activeId;
        }
        if (DB::table('role_assignments')->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', true)->exists()) {
            abort(400, 'Select an agency first.');
        }
        $first = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)->value('agency_id');
        abort_unless($first, 400);

        return (int) $first;
    }
}
