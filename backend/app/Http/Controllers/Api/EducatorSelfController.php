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
                'c.id', 'c.first_name', 'c.last_name', 'c.preferred_name', 'c.pronouns', 'c.gender',
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

        // The child's own record of everything logged about them — care moments
        // AND every sign-in/sign-out, each with WHO recorded it and when. This is
        // the compliance trail: "who had this child, and when" has to be
        // answerable from the child's record, not reconstructed from three screens.
        $tz = 'America/Toronto';
        $agencyTz = DB::table('children as c')
            ->leftJoin('families as f', 'f.id', '=', 'c.family_id')
            ->leftJoin('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->leftJoin('agencies as a', 'a.id', '=', 'ce.agency_id')
            ->where('c.id', $child)
            ->value('a.timezone');
        if ($agencyTz) $tz = $agencyTz;

        $byName = "NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),'')";

        $checks = DB::table('check_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.by_user_id')
            ->where('e.child_id', $child)
            ->orderByDesc('e.occurred_at')
            ->limit(120)
            ->get([
                'e.id', 'e.event_type', 'e.occurred_at', 'e.notes',
                DB::raw("$byName as by_name"),
            ])
            ->map(fn ($e) => (object) [
                'kind' => $e->event_type,          // check_in | check_out
                'group' => 'attendance',
                'detail' => null,
                'note' => $e->notes,
                'by' => $e->by_name,
                'at' => \Carbon\Carbon::parse($e->occurred_at)->timezone($tz)->format('Y-m-d H:i:s'),
            ]);

        $careLogs = DB::table('daily_care_logs as l')
            ->leftJoin('users as u', 'u.id', '=', 'l.recorded_by_id')
            ->where('l.child_id', $child)
            ->orderByDesc('l.occurred_at')
            ->limit(120)
            ->get([
                'l.log_type', 'l.occurred_at', 'l.details', 'l.notes',
                DB::raw("$byName as by_name"),
            ])
            ->map(fn ($l) => (object) [
                'kind' => $l->log_type,
                'group' => 'care',
                'detail' => $l->details,
                'note' => $l->notes,
                'by' => $l->by_name,
                'at' => \Carbon\Carbon::parse($l->occurred_at)->timezone($tz)->format('Y-m-d H:i:s'),
            ]);

        // The roster quick-log writes to daily_events, not daily_care_logs — read
        // both or half the child's history is missing.
        $eventLogs = DB::table('daily_events as d')
            ->leftJoin('users as u', 'u.id', '=', 'd.recorded_by_id')
            ->where('d.child_id', $child)
            ->whereNull('d.deleted_at')
            ->whereIn('d.event_type', ['diaper', 'bathroom', 'nap', 'meal', 'snack', 'bottle', 'sunscreen', 'mood'])
            ->orderByDesc('d.occurred_at')
            ->limit(120)
            ->get([
                'd.event_type', 'd.occurred_at', 'd.payload', 'd.notes',
                DB::raw("$byName as by_name"),
            ])
            ->map(function ($d) use ($tz) {
                $detail = null;
                $p = json_decode((string) $d->payload, true);
                if (is_array($p)) {
                    $vals = array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', array_values($p)));
                    $detail = $vals ? implode(', ', $vals) : null;
                }
                return (object) [
                    'kind' => $d->event_type,
                    'group' => 'care',
                    'detail' => $detail,
                    'note' => $d->notes,
                    'by' => $d->by_name,
                    'at' => \Carbon\Carbon::parse($d->occurred_at)->timezone($tz)->format('Y-m-d H:i:s'),
                ];
            });

        // Honour the agency's retention policy: the child's record only shows logs
        // inside the "Attendance & daily logs" window configured under Data
        // Retention & Compliance (agencies.settings -> compliance.daily_log_months,
        // 36 months by default). Older entries are past the period the agency says
        // it keeps them for, so they are not surfaced here.
        $months = $this->retentionMonths($child);
        $cutoff = \Illuminate\Support\Carbon::now($tz)->subMonths($months)->format('Y-m-d H:i:s');

        $all = $checks->concat($careLogs)->concat($eventLogs)->sortByDesc('at')->values();
        $history = $all->filter(fn ($h) => $h->at >= $cutoff)->values();

        return response()->json([
            'child' => $row,
            'guardians' => $guardians,
            'emergency_contacts' => $emergency,
            'pickup_authorizations' => $pickup,
            'history' => $history,
            'history_total' => $all->count(),
            'retention_months' => $months,
            'timezone' => $tz,
        ]);
    }

    /** The agency's "Attendance & daily logs" retention window, in months. */
    private function retentionMonths(int $childId): int
    {
        $settings = DB::table('children as c')
            ->leftJoin('families as f', 'f.id', '=', 'c.family_id')
            ->leftJoin('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->leftJoin('agencies as a', 'a.id', '=', 'ce.agency_id')
            ->where('c.id', $childId)
            ->value('a.settings');

        $decoded = $settings ? (json_decode((string) $settings, true) ?: []) : [];
        $months = (int) ($decoded['compliance']['daily_log_months'] ?? 36);

        return $months > 0 ? $months : 36;
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
