<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class CheckEventController extends Controller
{
    /** Display timezone (Eastern for Ontario) resolved from a room → centre → agency. */
    private function tzForRoom(int $roomId): string
    {
        $centreId = DB::table('rooms')->where('id', $roomId)->value('centre_id');
        return AgencyTime::tzForCentre($centreId ? (int) $centreId : null);
    }

    public function checkIn(Request $request): JsonResponse
    {
        if ($resp = $this->requireClockIn($request->user()->id)) return $resp;
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'room_id' => ['required', 'integer'],
            'mood' => ['nullable', 'in:happy,calm,tired,upset,sick'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->doCheckIn(
            $data['child_id'],
            $data['room_id'],
            $request->user()->id,
            $data['mood'] ?? null,
            $data['notes'] ?? null,
        );

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 422);
        }

        return response()->json($result, 201);
    }

    public function checkOut(Request $request): JsonResponse
    {
        if ($resp = $this->requireClockIn($request->user()->id)) return $resp;
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'room_id' => ['required', 'integer'],
            'pickup_person' => ['nullable', 'string', 'max:160'],
            'mood' => ['nullable', 'in:happy,calm,tired,upset,sick'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $today = now()->toDateString();
        $existing = DB::table('check_events')
            ->where('child_id', $data['child_id'])
            ->whereDate('occurred_at', $today)
            ->orderByDesc('occurred_at')
            ->first();

        if (!$existing || $existing->event_type !== 'check_in') {
            return response()->json(['message' => 'Child is not currently checked in.'], 422);
        }

        $notes = trim(($data['pickup_person'] ?? '').' '.($data['notes'] ?? '')) ?: null;

        $eventId = DB::table('check_events')->insertGetId([
            'child_id' => $data['child_id'],
            'room_id' => $data['room_id'],
            'event_type' => 'check_out',
            'occurred_at' => now(),
            'by_user_id' => $request->user()->id,
            'recorded_by_id' => $request->user()->id,
            'mood_at_event' => $data['mood'] ?? null,
            'notes' => $notes,
            'created_at' => now(),
        ]);

        // Tell the parents — who signed the child out, and when. Wrapped so a
        // failed notification can never undo a check-out that already happened.
        try {
            app(\App\Services\CheckEventNotifier::class)
                ->notify((int) $data['child_id'], 'check_out', (int) $request->user()->id);
        } catch (\Throwable $e) {}

        $this->logComplianceCheck((int) $data['child_id'], 'check_out', (int) $request->user()->id, $eventId);

        return response()->json([
            'event_id' => $eventId,
            'child_id' => $data['child_id'],
            'event_type' => 'check_out',
            'occurred_at' => now()->toIso8601String(),
            'time_display' => AgencyTime::fmt(now(), $this->tzForRoom((int) $data['room_id'])),
        ], 201);
    }

    /**
     * POST /api/v1/provider/check-in-batch
     * Check in multiple children at once (morning arrival rush).
     */
    public function checkInBatch(Request $request): JsonResponse
    {
        if ($resp = $this->requireClockIn($request->user()->id)) return $resp;
        $data = $request->validate([
            'room_id' => ['required', 'integer'],
            'child_ids' => ['required', 'array', 'min:1'],
            'child_ids.*' => ['integer'],
            'mood' => ['nullable', 'in:happy,calm,tired,upset,sick'],
        ]);

        $results = [
            'checked_in' => [],
            'skipped' => [],
            'errors' => [],
        ];

        foreach ($data['child_ids'] as $childId) {
            $result = $this->doCheckIn(
                (int) $childId,
                $data['room_id'],
                $request->user()->id,
                $data['mood'] ?? null,
                null,
            );

            if (isset($result['error'])) {
                if (str_contains($result['error'], 'Already')) {
                    $results['skipped'][] = ['child_id' => $childId, 'reason' => $result['error']];
                } else {
                    $results['errors'][] = ['child_id' => $childId, 'reason' => $result['error']];
                }
            } else {
                $results['checked_in'][] = $childId;
            }
        }

        return response()->json([
            'checked_in_count' => count($results['checked_in']),
            'skipped_count' => count($results['skipped']),
            'error_count' => count($results['errors']),
            'detail' => $results,
        ], 201);
    }

    private function doCheckIn(int $childId, int $roomId, int $userId, ?string $mood, ?string $notes): array
    {
        $child = DB::table('children')->where('id', $childId)->first();
        if (!$child) {
            return ['error' => 'Child not found'];
        }

        $today = now()->toDateString();
        $existing = DB::table('check_events')
            ->where('child_id', $childId)
            ->whereDate('occurred_at', $today)
            ->orderByDesc('occurred_at')
            ->first();

        $tz = $this->tzForRoom($roomId);
        if ($existing && $existing->event_type === 'check_in') {
            return ['error' => 'Already checked in at '.AgencyTime::fmt($existing->occurred_at, $tz)];
        }

        $eventId = DB::table('check_events')->insertGetId([
            'child_id' => $childId,
            'room_id' => $roomId,
            'event_type' => 'check_in',
            'occurred_at' => now(),
            'by_user_id' => $userId,
            'recorded_by_id' => $userId,
            'mood_at_event' => $mood,
            'notes' => $notes,
            'created_at' => now(),
        ]);

        // Tell the parents — including WHO signed the child in. This helper backs
        // both the single check-in and the batch, so hooking it here covers both.
        try {
            app(\App\Services\CheckEventNotifier::class)->notify((int) $childId, 'check_in', (int) $userId);
        } catch (\Throwable $e) {}

        // Compliance/security trail: record WHO signed the child in, and whether
        // it was a guardian (self-service) or a staff member acting on the
        // family's behalf (the manual workaround).
        $this->logComplianceCheck($childId, 'check_in', $userId, $eventId);

        return [
            'event_id' => $eventId,
            'child_id' => $childId,
            'event_type' => 'check_in',
            'occurred_at' => now()->toIso8601String(),
            'time_display' => AgencyTime::fmt(now(), $tz),
        ];
    }

    /**
     * An educator must be clocked in before they can check any child in or out —
     * it keeps attendance + ratio records honest. Directors / agency-admins /
     * platform-admins don't clock in, so the gate does NOT apply to them.
     * Returns a 422 JsonResponse to abort, or null to proceed.
     */
    private function requireClockIn(int $userId): ?JsonResponse
    {
        $roles = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)->pluck('role')->all();
        $isEducator = in_array('educator', $roles, true);
        $isSupervisor = (bool) array_intersect($roles, ['centre_director', 'agency_admin', 'platform_admin']);
        if (! $isEducator || $isSupervisor) return null;   // not a pure educator → no gate

        // A RECENT open punch, not any open punch. Without the time bound, a shift
        // that was never clocked out satisfies this forever — four accounts currently
        // carry one, the oldest 30 days old, and for them this gate has done nothing
        // for weeks while the screen still claimed it was enforced.
        //
        // 20 hours matches how DashboardExtrasController already decides who is on the
        // floor. Not a calendar-day test: someone who clocks in at 23:00 and works past
        // midnight is still on shift, and a day boundary would lock them out mid-shift.
        $open = DB::table('time_punches')
            ->where('user_id', $userId)
            ->whereNull('punched_out_at')
            ->where('punched_in_at', '>=', now()->subHours(20))
            ->exists();
        if ($open) return null;

        return response()->json([
            'message' => 'You must be clocked in before you can check children in or out.',
            'code' => 'not_clocked_in',
        ], 422);
    }

    /**
     * Write a semantic compliance entry to audit_logs for a check-in / check-out.
     * Distinguishes a guardian self-scan from a staff-performed action (the manual
     * workaround) so directors/admins have a clear who-did-what-on-whose-behalf
     * trail. Best-effort — never blocks the attendance event.
     */
    private function logComplianceCheck(int $childId, string $eventType, int $userId, ?int $eventId): void
    {
        try {
            $child = DB::table('children')->where('id', $childId)->first();
            if (! $child) return;

            $isGuardian = DB::table('guardians')
                ->where('family_id', $child->family_id)
                ->where('user_id', $userId)
                ->exists();

            $actor = DB::table('users')->where('id', $userId)->first();
            $actorName = $actor
                ? (trim(($actor->first_name ?? '').' '.($actor->last_name ?? '')) ?: ('User #'.$userId))
                : ('User #'.$userId);
            $childName = $child->preferred_name ?: $child->first_name;

            DB::table('audit_logs')->insert([
                'user_id'     => $userId,
                'action'      => 'child.'.$eventType.($isGuardian ? '' : '_by_staff'),
                'entity_type' => 'child',
                'entity_id'   => $childId,
                'payload'     => json_encode([
                    'child_id'   => $childId,
                    'child_name' => $childName,
                    'event_type' => $eventType,
                    'event_id'   => $eventId,
                    'by_user_id' => $userId,
                    'by_name'    => $actorName,
                    'actor_kind' => $isGuardian ? 'guardian' : 'staff',
                    'on_behalf'  => ! $isGuardian,
                ]),
                'ip_address'  => request()->ip(),
                'user_agent'  => substr((string) request()->userAgent(), 0, 500),
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {}
    }
}
