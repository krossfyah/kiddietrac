<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * v14: Staff scheduling + certifications.
 *
 * Schema:
 *   shifts(id, user_id, room_id, starts_at, ends_at, role enum, status enum, created_at)
 *   staff_certifications(id, user_id, cert_type enum, certifier?, issued_at?, expires_at?, document_url?, active)
 *   time_entries(id, user_id, centre_id, clocked_in_at, clocked_out_at?, total_break_min, notes?, shift_id?)
 */
final class SchedulingController extends Controller
{
    /**
     * GET /api/v1/director/schedule?centre_id=X&week_starting=YYYY-MM-DD
     * Weekly view of all shifts for the centre.
     */
    public function week(Request $request): JsonResponse
    {
        $centreId = (int) $request->input('centre_id');
        $week = $request->input('week_starting');
        if (! $week) {
            $week = Carbon::now()->startOfWeek(Carbon::MONDAY)->toDateString();
        }

        if (! $this->hasCentreAccess($request->user()->id, $centreId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $weekStart = Carbon::parse($week)->startOfDay();
        $weekEnd   = $weekStart->copy()->addDays(7);

        $rooms = DB::table('rooms')->where('centre_id', $centreId)->pluck('id')->all();

        $shifts = DB::table('shifts')
            ->whereIn('room_id', $rooms)
            ->where('starts_at', '>=', $weekStart->toDateTimeString())
            ->where('starts_at', '<', $weekEnd->toDateTimeString())
            ->orderBy('starts_at')
            ->get();

        $userIds = $shifts->pluck('user_id')->unique()->all();
        $users = !empty($userIds)
            ? DB::table('users')->whereIn('id', $userIds)->get()->keyBy('id')
            : collect();
        $roomMeta = DB::table('rooms')->whereIn('id', $rooms)->get()->keyBy('id');

        // Group by day
        $byDay = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $weekStart->copy()->addDays($i);
            $byDay[$d->toDateString()] = ['day_name' => $d->format('l'), 'shifts' => []];
        }

        foreach ($shifts as $s) {
            $u = $users[$s->user_id] ?? null;
            $r = $roomMeta[$s->room_id] ?? null;
            $startDate = Carbon::parse($s->starts_at)->toDateString();
            if (! isset($byDay[$startDate])) continue;
            $byDay[$startDate]['shifts'][] = [
                'id' => $s->id,
                'user_id' => $s->user_id,
                'user_name' => $u ? trim($u->first_name . ' ' . $u->last_name) : 'Unknown',
                'room_id' => $s->room_id,
                'room_name' => $r->name ?? 'Room',
                'starts_at' => $s->starts_at,
                'ends_at' => $s->ends_at,
                'starts_hm' => Carbon::parse($s->starts_at)->format('H:i'),
                'ends_hm' => Carbon::parse($s->ends_at)->format('H:i'),
                'role' => $s->role,
                'status' => $s->status,
            ];
        }

        return response()->json([
            'centre_id' => $centreId,
            'week_starting' => $weekStart->toDateString(),
            'days' => $byDay,
            'total_shifts' => $shifts->count(),
        ]);
    }

    /**
     * POST /api/v1/director/schedule/shift
     */
    public function createShift(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'room_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'role' => ['nullable', 'in:lead,support,floater,volunteer'],
        ]);

        $room = DB::table('rooms')->where('id', $data['room_id'])->first();
        if (! $room) return response()->json(['message' => 'Room not found'], 404);
        if (! $this->hasCentreAccess($request->user()->id, $room->centre_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $shiftId = DB::table('shifts')->insertGetId([
            'user_id' => $data['user_id'],
            'room_id' => $data['room_id'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'role' => $data['role'] ?? 'support',
            'status' => 'scheduled',
            'created_at' => now(),
        ]);

        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'action' => 'shift.created',
            'entity_type' => 'shift',
            'entity_id' => $shiftId,
            'payload' => json_encode($data),
            'created_at' => now(),
        ]);

        return response()->json(['success' => true, 'shift_id' => $shiftId], 201);
    }

    /**
     * PATCH /api/v1/director/schedule/shift/{id}
     */
    public function updateShift(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'role' => ['nullable', 'in:lead,support,floater,volunteer'],
            'status' => ['nullable', 'in:scheduled,active,completed,cancelled'],
            'user_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
        ]);

        $shift = DB::table('shifts')->where('id', $id)->first();
        if (! $shift) return response()->json(['message' => 'Not found'], 404);

        $room = DB::table('rooms')->where('id', $shift->room_id)->first();
        if (! $this->hasCentreAccess($request->user()->id, $room->centre_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $update = array_filter($data, fn($v) => $v !== null);
        if (!empty($update)) {
            DB::table('shifts')->where('id', $id)->update($update);
        }

        return response()->json(['success' => true]);
    }

    /**
     * DELETE /api/v1/director/schedule/shift/{id}
     */
    public function deleteShift(Request $request, int $id): JsonResponse
    {
        $shift = DB::table('shifts')->where('id', $id)->first();
        if (! $shift) return response()->json(['message' => 'Not found'], 404);
        $room = DB::table('rooms')->where('id', $shift->room_id)->first();
        if (! $this->hasCentreAccess($request->user()->id, $room->centre_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        DB::table('shifts')->where('id', $id)->delete();

        return response()->json(['success' => true]);
    }

    /**
     * GET /api/v1/director/schedule/staff?centre_id=X
     * List staff (users) eligible to be scheduled — anyone with educator/centre_director
     * role at this centre.
     */
    public function staffList(Request $request): JsonResponse
    {
        $centreId = (int) $request->input('centre_id');
        if (! $this->hasCentreAccess($request->user()->id, $centreId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $centre = DB::table('centres')->where('id', $centreId)->first();

        $staff = DB::table('users')
            ->join('role_assignments', 'role_assignments.user_id', '=', 'users.id')
            ->where(function ($q) use ($centreId, $centre) {
                $q->where('role_assignments.centre_id', $centreId)
                  ->orWhere('role_assignments.agency_id', $centre->agency_id);
            })
            ->whereIn('role_assignments.role', ['educator', 'centre_director'])
            ->where('role_assignments.active', true)
            ->where('users.status', 'active')
            ->whereNull('users.deleted_at')
            ->distinct()
            ->select('users.id', 'users.first_name', 'users.last_name', 'users.email', 'users.photo_url')
            ->get();

        return response()->json([
            'staff' => $staff->map(function ($u) {
                return [
                    'id' => $u->id,
                    'name' => trim($u->first_name . ' ' . $u->last_name),
                    'email' => $u->email,
                    'photo_url' => $u->photo_url,
                ];
            }),
        ]);
    }

    /**
     * GET /api/v1/director/timesheets?centre_id=X&from=&to=
     * Export-ready timesheet rows. CSV is generated client-side from this JSON.
     */
    public function timesheets(Request $request): JsonResponse
    {
        $centreId = (int) $request->input('centre_id');
        $from = $request->input('from', Carbon::now()->startOfMonth()->toDateString());
        $to = $request->input('to', Carbon::now()->toDateString());

        if (! $this->hasCentreAccess($request->user()->id, $centreId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $entries = DB::table('time_entries')
            ->join('users', 'users.id', '=', 'time_entries.user_id')
            ->where('time_entries.centre_id', $centreId)
            ->where('time_entries.clocked_in_at', '>=', Carbon::parse($from)->startOfDay())
            ->where('time_entries.clocked_in_at', '<=', Carbon::parse($to)->endOfDay())
            ->whereNotNull('time_entries.clocked_out_at')
            ->orderBy('users.last_name')
            ->orderBy('time_entries.clocked_in_at')
            ->select(
                'time_entries.*',
                'users.first_name',
                'users.last_name',
                'users.email'
            )
            ->get();

        $rows = $entries->map(function ($e) {
            $in = Carbon::parse($e->clocked_in_at);
            $out = Carbon::parse($e->clocked_out_at);
            $minutes = $out->diffInMinutes($in) - (int) $e->total_break_min;
            return [
                'date' => $in->toDateString(),
                'staff_name' => trim($e->first_name . ' ' . $e->last_name),
                'staff_email' => $e->email,
                'clock_in' => $in->format('H:i'),
                'clock_out' => $out->format('H:i'),
                'break_min' => (int) $e->total_break_min,
                'worked_min' => max(0, $minutes),
                'worked_hours' => round(max(0, $minutes) / 60, 2),
                'notes' => $e->notes,
            ];
        });

        return response()->json([
            'centre_id' => $centreId,
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'total_hours' => round($rows->sum('worked_min') / 60, 2),
            'staff_count' => $rows->pluck('staff_email')->unique()->count(),
        ]);
    }

    /**
     * GET /api/v1/director/certifications?centre_id=X
     * Active certs across staff at this centre, with expiry alerts.
     */
    public function certifications(Request $request): JsonResponse
    {
        $centreId = (int) $request->input('centre_id');
        if (! $this->hasCentreAccess($request->user()->id, $centreId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $centre = DB::table('centres')->where('id', $centreId)->first();
        $staffUserIds = DB::table('role_assignments')
            ->where(function ($q) use ($centreId, $centre) {
                $q->where('centre_id', $centreId)->orWhere('agency_id', $centre->agency_id);
            })
            ->whereIn('role', ['educator', 'centre_director'])
            ->where('active', true)
            ->pluck('user_id')
            ->unique()
            ->all();

        if (empty($staffUserIds)) {
            return response()->json(['certifications' => [], 'expiring_soon' => 0, 'expired' => 0]);
        }

        $certs = DB::table('staff_certifications')
            ->join('users', 'users.id', '=', 'staff_certifications.user_id')
            ->whereIn('staff_certifications.user_id', $staffUserIds)
            ->where('staff_certifications.active', true)
            ->orderBy('staff_certifications.expires_at')
            ->select(
                'staff_certifications.*',
                'users.first_name',
                'users.last_name'
            )
            ->get();

        $now = Carbon::now();
        $expiringSoon = 0;
        $expired = 0;

        $rows = $certs->map(function ($c) use ($now, &$expiringSoon, &$expired) {
            $exp = $c->expires_at ? Carbon::parse($c->expires_at) : null;
            $status = 'ok';
            $daysUntil = null;
            if ($exp) {
                $daysUntil = $now->diffInDays($exp, false); // signed
                if ($daysUntil < 0) { $status = 'expired'; $expired++; }
                elseif ($daysUntil <= 30) { $status = 'expiring_soon'; $expiringSoon++; }
                elseif ($daysUntil <= 90) { $status = 'warning'; }
            }
            return [
                'id' => $c->id,
                'staff_name' => trim($c->first_name . ' ' . $c->last_name),
                'cert_type' => $c->cert_type,
                'certifier' => $c->certifier,
                'issued_at' => $c->issued_at,
                'expires_at' => $c->expires_at,
                'days_until_expiry' => $daysUntil ? (int) $daysUntil : null,
                'status' => $status,
                'document_url' => $c->document_url,
            ];
        });

        return response()->json([
            'centre_id' => $centreId,
            'certifications' => $rows,
            'expiring_soon' => $expiringSoon,
            'expired' => $expired,
            'total_active' => $rows->count(),
        ]);
    }

    private function hasCentreAccess(int $userId, int $centreId): bool
    {
        return DB::table('role_assignments')
            ->where('user_id', $userId)
            ->whereIn('role', ['centre_director', 'agency_admin'])
            ->where('active', true)
            ->where(function ($q) use ($centreId) {
                $q->where('centre_id', $centreId)
                  ->orWhereIn('agency_id', function ($qq) use ($centreId) {
                      $qq->select('agency_id')->from('centres')->where('id', $centreId);
                  });
            })
            ->exists();
    }
}
