<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class CentreController extends Controller
{
    use ResolvesCentreContext;

    public function directorDashboard(Request $request): JsonResponse
    {
        $centre = $this->resolveCentre($request->user());

        if (! $centre) {
            return response()->json(['message' => 'No centre assigned to this account.'], 404);
        }

        $today = now()->toDateString();

        $rooms = DB::table('rooms')
            ->where('centre_id', $centre->id)
            ->where('active', true)
            ->orderBy('age_min_months')
            ->get();

        $roomStats = $rooms->map(fn ($room) => $this->getRoomStats($room, $today))->all();

        $totalEnrolled = DB::table('children')
            ->join('enrollments', 'enrollments.child_id', '=', 'children.id')
            ->join('rooms', 'rooms.id', '=', 'enrollments.room_id')
            ->where('rooms.centre_id', $centre->id)
            ->where('children.enrollment_status', 'enrolled')
            ->whereNull('enrollments.end_date')
            ->whereNull('children.deleted_at')
            ->distinct()
            ->count('children.id');

        $presentNow = $this->countCurrentlyPresent($centre->id, $today);

        $staffOnFloor = DB::table('time_entries')
            ->where('centre_id', $centre->id)
            ->whereDate('clocked_in_at', $today)
            ->whereNull('clocked_out_at')
            ->count();

        $receivables = (float) DB::table('invoices')
            ->where('centre_id', $centre->id)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->sum('balance_due');

        $incidentsToReview = DB::table('incidents')
            ->join('rooms', 'rooms.id', '=', 'incidents.room_id')
            ->where('rooms.centre_id', $centre->id)
            ->where('incidents.status', 'submitted')
            ->count();

        $expiringCerts = DB::table('staff_certifications')
            ->join('role_assignments', 'role_assignments.user_id', '=', 'staff_certifications.user_id')
            ->where('role_assignments.centre_id', $centre->id)
            ->where('staff_certifications.active', true)
            ->whereNotNull('staff_certifications.expires_at')
            ->where('staff_certifications.expires_at', '<=', now()->addDays(60))
            ->count();

        $unreadMessages = DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->join('families', 'families.id', '=', 'conversations.family_id')
            ->where('families.centre_id', $centre->id)
            ->where('messages.sender_id', '!=', $request->user()->id)
            ->whereNull('messages.read_at')
            ->count();

        return response()->json([
            'centre' => [
                'id' => $centre->id,
                'name' => $centre->name,
                'address' => trim(($centre->address_line1 ?? '').', '.($centre->city ?? '')),
                'license_number' => $centre->license_number,
                'license_capacity' => $centre->license_capacity,
                'cwelcc_enrolled' => (bool) $centre->cwelcc_enrolled,
            ],
            'stats' => [
                'total_enrolled' => $totalEnrolled,
                'present_now' => $presentNow,
                'capacity' => $centre->license_capacity,
                'utilization_pct' => $centre->license_capacity > 0
                    ? (int) round($totalEnrolled / $centre->license_capacity * 100)
                    : 0,
                'staff_on_floor' => $staffOnFloor,
                'receivables' => $receivables,
                'incidents_to_review' => $incidentsToReview,
                'expiring_certifications' => $expiringCerts,
                'unread_messages' => $unreadMessages,
            ],
            'rooms' => $roomStats,
        ]);
    }

    public function mine(Request $request): JsonResponse
    {
        $centre = $this->resolveCentre($request->user());

        return $centre
            ? response()->json(['centre' => $centre])
            : response()->json(['message' => 'Not found'], 404);
    }

    public function update(Request $request): JsonResponse
    {
        $centre = $this->resolveCentre($request->user());

        if (! $centre) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $data = $request->validate([
            'name' => ['string', 'max:180'],
            'license_number' => ['nullable', 'string', 'max:60'],
            'license_capacity' => ['integer', 'min:0'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:80'],
            'province' => ['nullable', 'string', 'max:40'],
            'postal_code' => ['nullable', 'string', 'max:12'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:180'],
            'open_time' => ['nullable', 'date_format:H:i'],
            'close_time' => ['nullable', 'date_format:H:i'],
            'cwelcc_enrolled' => ['boolean'],
        ]);

        DB::table('centres')
            ->where('id', $centre->id)
            ->update([...$data, 'updated_at' => now()]);

        return response()->json([
            'centre' => DB::table('centres')->where('id', $centre->id)->first(),
        ]);
    }

    public function compliance(Request $request): JsonResponse
    {
        $centre = $this->resolveCentre($request->user());

        if (! $centre) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $rooms = DB::table('rooms')
            ->where('centre_id', $centre->id)
            ->where('active', true)
            ->get();

        $today = now()->toDateString();
        $roomCompliance = $rooms->map(function ($room) use ($today) {
            $stats = $this->getRoomStats($room, $today);
            return [
                'room_id' => $room->id,
                'room_name' => $room->name,
                'children_present' => $stats['children_present'],
                'educators_present' => $stats['educators_present'],
                'required_educators' => $stats['required_educators'],
                'compliant' => $stats['compliant'],
                'status' => $stats['status'],
            ];
        });

        $missingImmunizations = DB::table('children')
            ->join('enrollments', 'enrollments.child_id', '=', 'children.id')
            ->join('rooms', 'rooms.id', '=', 'enrollments.room_id')
            ->where('rooms.centre_id', $centre->id)
            ->where('children.enrollment_status', 'enrolled')
            ->whereNotIn('children.id', fn ($q) => $q->select('child_id')->from('immunizations'))
            ->count();

        $certStats = DB::table('staff_certifications')
            ->join('role_assignments', 'role_assignments.user_id', '=', 'staff_certifications.user_id')
            ->where('role_assignments.centre_id', $centre->id)
            ->where('staff_certifications.active', true)
            ->selectRaw("
                SUM(CASE WHEN expires_at < NOW() THEN 1 ELSE 0 END) as expired,
                SUM(CASE WHEN expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as expiring_30d,
                SUM(CASE WHEN expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 90 DAY) THEN 1 ELSE 0 END) as expiring_90d,
                COUNT(*) as total
            ")
            ->first();

        return response()->json([
            'rooms' => $roomCompliance,
            'all_rooms_compliant' => $roomCompliance->every('compliant'),
            'immunization' => [
                'children_missing_records' => $missingImmunizations,
            ],
            'staff_certifications' => [
                'total' => (int) ($certStats->total ?? 0),
                'expired' => (int) ($certStats->expired ?? 0),
                'expiring_30d' => (int) ($certStats->expiring_30d ?? 0),
                'expiring_90d' => (int) ($certStats->expiring_90d ?? 0),
            ],
        ]);
    }

    /**
     * GET /api/v1/director/compliance/export
     * Returns a printable HTML report. Browser's "Save as PDF" turns it into a PDF.
     */
    public function complianceExport(Request $request): Response
    {
        $centre = $this->resolveCentre($request->user());

        if (! $centre) {
            return response('Not found', 404);
        }

        $today = now();

        $rooms = DB::table('rooms')
            ->where('centre_id', $centre->id)
            ->where('active', true)
            ->orderBy('age_min_months')
            ->get();

        $roomCompliance = $rooms->map(fn ($r) => $this->getRoomStats($r, $today->toDateString()));

        $staff = DB::table('users')
            ->join('role_assignments', 'role_assignments.user_id', '=', 'users.id')
            ->where('role_assignments.centre_id', $centre->id)
            ->where('role_assignments.active', true)
            ->whereIn('role_assignments.role', ['educator', 'centre_director'])
            ->select('users.id', 'users.first_name', 'users.last_name', 'role_assignments.role')
            ->distinct()
            ->orderBy('users.first_name')
            ->get();

        $certs = DB::table('staff_certifications')
            ->whereIn('user_id', $staff->pluck('id'))
            ->where('active', true)
            ->get()
            ->groupBy('user_id');

        $html = $this->buildComplianceHtml($centre, $today, $roomCompliance, $staff, $certs);

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function attendanceReport(Request $request): JsonResponse
    {
        $centre = $this->resolveCentre($request->user());
        if (! $centre) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());

        $byDay = DB::table('check_events as ce')
            ->join('rooms', 'rooms.id', '=', 'ce.room_id')
            ->where('rooms.centre_id', $centre->id)
            ->where('ce.event_type', 'check_in')
            ->whereBetween(DB::raw('DATE(ce.occurred_at)'), [$from, $to])
            ->groupBy(DB::raw('DATE(ce.occurred_at)'))
            ->selectRaw('DATE(ce.occurred_at) as date, COUNT(DISTINCT ce.child_id) as children_present')
            ->orderBy('date')
            ->get();

        $totalChildDays = (int) $byDay->sum('children_present');
        $averagePerDay = $byDay->count() > 0 ? round($totalChildDays / $byDay->count(), 1) : 0;

        return response()->json([
            'from' => $from,
            'to' => $to,
            'by_day' => $byDay,
            'total_child_days' => $totalChildDays,
            'average_per_day' => $averagePerDay,
        ]);
    }

    // ─── helpers ────────────────────────────────────────────────────

    private function countCurrentlyPresent(int $centreId, string $date): int
    {
        return DB::table('check_events as ci')
            ->whereDate('ci.occurred_at', $date)
            ->where('ci.event_type', 'check_in')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('check_events as co')
                ->whereColumn('co.child_id', 'ci.child_id')
                ->where('co.event_type', 'check_out')
                ->where('co.occurred_at', '>', DB::raw('ci.occurred_at'))
                ->whereDate('co.occurred_at', $date))
            ->join('rooms', 'rooms.id', '=', 'ci.room_id')
            ->where('rooms.centre_id', $centreId)
            ->distinct('ci.child_id')
            ->count('ci.child_id');
    }

    private function getRoomStats(object $room, string $date): array
    {
        $childrenPresent = DB::table('check_events as ci')
            ->where('ci.room_id', $room->id)
            ->where('ci.event_type', 'check_in')
            ->whereDate('ci.occurred_at', $date)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('check_events as co')
                ->whereColumn('co.child_id', 'ci.child_id')
                ->where('co.event_type', 'check_out')
                ->where('co.occurred_at', '>', DB::raw('ci.occurred_at')))
            ->distinct('ci.child_id')
            ->count('ci.child_id');

        $educatorsPresent = DB::table('shifts')
            ->where('room_id', $room->id)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->where('status', 'active')
            ->count();

        if ($educatorsPresent === 0) {
            $clockedIn = DB::table('time_entries')
                ->where('centre_id', $room->centre_id)
                ->whereDate('clocked_in_at', $date)
                ->whereNull('clocked_out_at')
                ->count();
            $totalRooms = DB::table('rooms')
                ->where('centre_id', $room->centre_id)
                ->where('active', true)
                ->count();
            $educatorsPresent = $totalRooms > 0 ? (int) ceil($clockedIn / $totalRooms) : $clockedIn;
        }

        $required = $childrenPresent === 0
            ? 0
            : (int) ceil($childrenPresent / max(1, (int) $room->ratio_children));

        $compliant = $educatorsPresent >= $required;
        $status = match (true) {
            ! $compliant => 'breach',
            $educatorsPresent - $required <= 0 && $childrenPresent > 0 => 'tight',
            default => 'ok',
        };

        return [
            'room_id' => $room->id,
            'room_name' => $room->name,
            'age_group' => $room->age_group,
            'color_hex' => $room->color_hex,
            'capacity' => $room->capacity,
            'children_present' => $childrenPresent,
            'educators_present' => $educatorsPresent,
            'required_educators' => $required,
            'ratio_target' => "{$room->ratio_educators}:{$room->ratio_children}",
            'compliant' => $compliant,
            'status' => $status,
            'utilization_pct' => $room->capacity > 0
                ? (int) round($childrenPresent / $room->capacity * 100)
                : 0,
        ];
    }

    private function buildComplianceHtml($centre, $today, $roomCompliance, $staff, $certs): string
    {
        $centreName = htmlspecialchars($centre->name);
        $licenseNumber = htmlspecialchars($centre->license_number ?? 'N/A');
        $address = htmlspecialchars(trim(($centre->address_line1 ?? '').' '.($centre->city ?? '').' '.($centre->province ?? '').' '.($centre->postal_code ?? '')));
        $dateStr = $today->format('F j, Y \a\t g:i A');

        $roomRows = '';
        foreach ($roomCompliance as $r) {
            $statusBadge = match ($r['status']) {
                'breach' => '<span style="color: #c0392b; font-weight: bold;">⚠ BREACH</span>',
                'tight' => '<span style="color: #d35400; font-weight: bold;">⚠ TIGHT</span>',
                default => '<span style="color: #27ae60; font-weight: bold;">✓ COMPLIANT</span>',
            };
            $roomRows .= "<tr>
                <td>{$r['room_name']}</td>
                <td>{$r['age_group']}</td>
                <td style='text-align: center'>{$r['children_present']}</td>
                <td style='text-align: center'>{$r['educators_present']} / {$r['required_educators']}</td>
                <td style='text-align: center'>{$r['ratio_target']}</td>
                <td style='text-align: center'>{$statusBadge}</td>
            </tr>";
        }

        $staffRows = '';
        foreach ($staff as $s) {
            $sCerts = $certs[$s->id] ?? collect();
            $certList = $sCerts->map(function ($c) {
                $expires = $c->expires_at ? date('M Y', strtotime($c->expires_at)) : 'No expiry';
                $exp = $c->expires_at && strtotime($c->expires_at) < strtotime('+60 days')
                    ? '<span style="color: #c0392b;">'.$expires.'</span>'
                    : $expires;
                return "{$c->cert_type} (exp. {$exp})";
            })->implode('<br>') ?: '<em>No certifications on file</em>';

            $staffRows .= "<tr>
                <td>{$s->first_name} {$s->last_name}</td>
                <td>{$s->role}</td>
                <td>{$certList}</td>
            </tr>";
        }

        return <<<HTML
<!DOCTYPE html>
<html><head>
<meta charset="UTF-8">
<title>Compliance Report — {$centreName}</title>
<style>
  body { font-family: 'Plus Jakarta Sans', -apple-system, sans-serif; max-width: 8.5in; margin: 0 auto; padding: 0.5in; color: #1F2937; }
  h1 { color: #1F6080; border-bottom: 3px solid #1F6080; padding-bottom: 8px; margin-bottom: 4px; }
  h2 { color: #1F6080; margin-top: 32px; }
  .meta { color: #6B7280; font-size: 13px; margin-bottom: 24px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
  th { background: #F3F4F6; padding: 10px; text-align: left; border-bottom: 2px solid #D1D5DB; font-size: 13px; }
  td { padding: 10px; border-bottom: 1px solid #E5E7EB; font-size: 14px; vertical-align: top; }
  .footer { margin-top: 48px; font-size: 12px; color: #9CA3AF; text-align: center; }
  @media print { body { padding: 0; } }
</style>
</head><body>

<h1>Childcare Centre Compliance Report</h1>
<div class="meta">
  <strong>{$centreName}</strong><br>
  License: {$licenseNumber}<br>
  Address: {$address}<br>
  Generated: {$dateStr}
</div>

<h2>Room Compliance — Educator-to-Child Ratios</h2>
<table>
<thead><tr>
  <th>Room</th><th>Age Group</th><th>Children</th><th>Educators (cur/req)</th><th>Target Ratio</th><th>Status</th>
</tr></thead>
<tbody>{$roomRows}</tbody>
</table>

<h2>Staff Certifications</h2>
<table>
<thead><tr>
  <th>Staff Member</th><th>Role</th><th>Certifications</th>
</tr></thead>
<tbody>{$staffRows}</tbody>
</table>

<div class="footer">
Generated by Kiddietrac · This document is a real-time compliance snapshot.<br>
For official Ministry reporting, please verify with the centre director.
</div>

</body></html>
HTML;
    }

    /**
     * v21: Returns centres visible to the current user.
     * agency_admin -> all centres in their agency
     * centre_director -> only their centres (via role_assignments)
     * others -> empty
     */
    public function myCentres(\Illuminate\Http\Request $request)
    {
        $user = $request->user();
        if (!$user) return response()->json(['centres' => []]);

        $roles = \Illuminate\Support\Facades\DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->get(['role', 'centre_id', 'agency_id']);

        $isAgencyAdmin = $roles->contains(function ($r) { return $r->role === 'agency_admin'; });

        $q = \Illuminate\Support\Facades\DB::table('centres')
            ->select('id', 'name', 'address_line1 as address', 'city', 'province')
            ->orderBy('name');

        if (!$isAgencyAdmin) {
            $centreIds = $roles->whereIn('role', ['centre_director', 'agency_admin'])
                ->pluck('centre_id')->filter()->unique()->all();
            if (empty($centreIds)) return response()->json(['centres' => []]);
            $q->whereIn('id', $centreIds);
        } else {
            // Agency admin: scope to their agency_id if any
            $agencyIds = $roles->pluck('agency_id')->filter()->unique()->all();
            if (!empty($agencyIds)) $q->whereIn('agency_id', $agencyIds);
        }

        return response()->json(['centres' => $q->get()]);
    }

}
