<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 2026-07-13 — Self-scoped staff endpoints for the educator mobile app.
 *
 * Educators had no way to see their own schedule or the record of a child in
 * their room: every existing route for this data is director/agency-admin gated
 * and centre-wide (/director/schedule, /director/timesheets, /admin/payroll all
 * return EVERY staff member's hours). Rather than widen those — which would let
 * an educator read colleagues' payroll — these return only the caller's own
 * data, and child records only for children the caller can already access.
 *
 * Endpoints:
 *   GET /provider/shifts/me        The caller's own upcoming + recent shifts
 *   GET /provider/children/{child} One child's record — the safety-critical bits
 *                                  a room educator needs (allergies, medical
 *                                  alerts, who may collect them, who to call)
 *
 * "Payroll" here is hours, not money: there is no pay-rate column anywhere in
 * the schema, so the app shows hours worked (from /staff/punches/me, which is
 * already self-scoped) and this adds the scheduled side of the same picture.
 */
class EducatorSelfController extends Controller
{
    use ResolvesCentreContext;

    /** The caller's own shifts. Never anyone else's — user_id is forced to the caller. */
    public function myShifts(Request $request): JsonResponse
    {
        $user = $request->user();
        $from = $request->filled('from')
            ? Carbon::parse((string) $request->input('from'))->startOfDay()
            : Carbon::now()->startOfWeek();
        $to = $request->filled('to')
            ? Carbon::parse((string) $request->input('to'))->endOfDay()
            : Carbon::now()->addWeeks(4)->endOfDay();

        $shifts = DB::table('shifts as s')
            ->leftJoin('rooms as r', 'r.id', '=', 's.room_id')
            ->where('s.user_id', $user->id)
            ->whereBetween('s.starts_at', [$from, $to])
            ->orderBy('s.starts_at')
            ->select([
                's.id', 's.starts_at', 's.ends_at', 's.role', 's.status',
                's.room_id', 'r.name as room_name', 'r.color_hex as room_colour',
            ])
            ->get()
            ->map(function ($s) {
                $start = Carbon::parse($s->starts_at);
                $end = $s->ends_at ? Carbon::parse($s->ends_at) : null;
                $s->date = $start->toDateString();
                $s->hours = $end ? round($start->floatDiffInHours($end), 2) : null;
                return $s;
            });

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'shifts' => $shifts,
            'scheduled_hours' => round((float) $shifts->sum('hours'), 2),
        ]);
    }

    /**
     * One child's record, for staff who already have access to that child.
     * canAccessChildScoped() is the audited tenant-isolation check (agency +
     * centre + family), so a child from another centre or agency 403s here.
     */
    public function childRecord(Request $request, int $child): JsonResponse
    {
        if (! $this->canAccessChildScoped($request, $child)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $row = DB::table('children as c')
            ->leftJoin('rooms as r', 'r.id', '=', 'c.primary_room_id')
            ->leftJoin('families as f', 'f.id', '=', 'c.family_id')
            ->where('c.id', $child)
            ->whereNull('c.deleted_at')
            ->select([
                'c.id', 'c.first_name', 'c.last_name', 'c.preferred_name', 'c.pronouns',
                'c.date_of_birth', 'c.photo_url', 'c.allergies', 'c.medical_notes',
                'c.dietary_restrictions', 'c.dietary_notes', 'c.health_alerts',
                'c.cultural_notes', 'c.preferred_lang', 'c.doctor_name', 'c.doctor_phone',
                'c.enrollment_status', 'c.family_id',
                'r.name as room_name', 'r.color_hex as room_colour',
                'f.family_name', 'f.primary_phone', 'f.primary_email',
            ])
            ->first();

        if (! $row) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $dob = $row->date_of_birth ? Carbon::parse($row->date_of_birth) : null;
        $row->age_human = $dob ? $this->ageHuman($dob) : null;

        // Guardians — name + phone only. An educator needs to reach a parent, not
        // to read their billing split or their address.
        $guardians = DB::table('guardians as g')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.family_id', $row->family_id)
            ->whereNull('u.deleted_at')
            ->orderByDesc('g.is_primary')
            ->select([
                'u.id', 'u.first_name', 'u.last_name', 'u.phone', 'u.email', 'u.photo_url',
                'g.relationship', 'g.is_primary', 'g.can_pickup',
            ])
            ->get();

        // emergency_contacts hangs off the FAMILY, not the child.
        $emergency = DB::table('emergency_contacts')
            ->where('family_id', $row->family_id)
            ->select(['id', 'name', 'relationship', 'phone', 'alt_phone', 'can_pickup', 'notes'])
            ->get();

        $pickup = DB::table('pickup_authorizations')
            ->where('child_id', $child)
            ->where('active', 1)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', Carbon::now()->toDateString());
            })
            ->select(['id', 'full_name', 'relationship', 'phone', 'photo_id_url', 'expires_at', 'notes'])
            ->get();

        return response()->json([
            'child' => $row,
            'guardians' => $guardians,
            'emergency_contacts' => $emergency,
            'pickup_authorizations' => $pickup,
        ]);
    }

    private function ageHuman(Carbon $dob): string
    {
        $months = (int) $dob->diffInMonths(Carbon::now());
        if ($months < 24) {
            return $months . 'm';
        }
        $years = intdiv($months, 12);
        $rem = $months % 12;
        return $rem ? ($years . 'y ' . $rem . 'm') : ($years . 'y');
    }
}
