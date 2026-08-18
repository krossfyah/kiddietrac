<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p56 — Procare-parity operations:
 *   - Closure / holiday calendar
 *   - Late pickup fee logging
 *   - Real-time room ratio compliance check
 *   - Bus routes + transportation rosters
 *   - Auto room rotation (age-band promotions)
 */
final class OperationsV2Controller extends Controller
{
    // =========================================================
    // Closure / holiday calendar
    // =========================================================
    public function closures(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id');
        $rows = DB::table('centre_closures as cc')
            ->join('centres as c', 'c.id', '=', 'cc.centre_id')
            ->whereIn('cc.centre_id', $centreIds)
            ->orderBy('cc.closure_date')
            ->select('cc.*', 'c.name as centre_name')
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function addClosure(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => 'required|integer',
            'closure_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:closure_date',
            'closure_type' => 'required|in:holiday,pd_day,emergency,renovation,other',
            'reason' => 'nullable|string|max:200',
            'affects_billing' => 'nullable|boolean',
        ]);
        $this->assertCentreAccess($request, (int) $data['centre_id']);
        $id = DB::table('centre_closures')->insertGetId([
            'centre_id' => $data['centre_id'],
            'closure_date' => $data['closure_date'],
            'end_date' => $data['end_date'] ?? null,
            'closure_type' => $data['closure_type'],
            'reason' => $data['reason'] ?? null,
            'affects_billing' => $data['affects_billing'] ?? true,
            'created_by_id' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Notify all families at this centre
        $familyIds = DB::table('families')->where('centre_id', $data['centre_id'])->whereNull('deleted_at')->pluck('id');
        $guardianIds = DB::table('guardians')->whereIn('family_id', $familyIds)->pluck('user_id')->unique();
        foreach ($guardianIds as $gid) {
            DB::table('notifications')->insert([
                'user_id' => $gid, 'type' => 'closure',
                'title' => 'Centre closed: ' . Carbon::parse($data['closure_date'])->format('M j'),
                'body' => $data['reason'] ?? ucfirst($data['closure_type']),
                'data' => json_encode(['link' => '#closures', 'closure_id' => $id]),
                'created_at' => now(),
            ]);
        }
        return response()->json(['id' => $id], 201);
    }

    public function removeClosure(Request $request, int $id): JsonResponse
    {
        $row = DB::table('centre_closures')->where('id', $id)->first();
        abort_unless($row, 404);
        $this->assertCentreAccess($request, (int) $row->centre_id);
        DB::table('centre_closures')->where('id', $id)->delete();
        return response()->json(['status' => 'removed']);
    }

    // =========================================================
    // Late pickup fee — director logs the pickup
    // =========================================================
    /** Centres + children for the late-pickup log picker, scoped to the caller's agency. */
    public function latePickupOptions(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) return response()->json(['centres' => [], 'children' => []]);
        $centres = DB::table('centres')->where('agency_id', $agencyId)->orderBy('name')->get(['id', 'name']);
        $children = DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->whereIn('f.centre_id', $centres->pluck('id'))
            ->whereNull('c.deleted_at')
            ->orderBy('c.first_name')->get(['c.id', 'c.first_name', 'c.last_name']);
        return response()->json(['centres' => $centres, 'children' => $children]);
    }

    public function logLatePickup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => 'required|integer',
            'child_id' => 'required|integer',
            'pickup_at' => 'required|date',
            'close_time' => 'required',
            'notes' => 'nullable|string|max:500',
        ]);
        $this->assertCentreAccess($request, (int) $data['centre_id']);
        $centre = DB::table('centres')->where('id', $data['centre_id'])->first();
        $agency = DB::table('agencies')->where('id', $centre->agency_id)->first();
        $perMin = (float) ($agency->late_pickup_per_minute ?? 1.00);
        $grace = (int) ($agency->late_pickup_grace_minutes ?? 5);

        $pickup = Carbon::parse($data['pickup_at']);
        $close = Carbon::parse($pickup->toDateString() . ' ' . $data['close_time']);
        $minutesLate = max(0, (int) $close->diffInMinutes($pickup, false));
        if ($minutesLate <= $grace) {
            return response()->json(['status' => 'within_grace', 'minutes_late' => $minutesLate]);
        }
        $chargeable = $minutesLate - $grace;
        $fee = round($chargeable * $perMin, 2);

        $child = DB::table('children')->where('id', $data['child_id'])->first();
        $family = DB::table('families')->where('id', $child->family_id)->first();

        // Append a line to the most-recent open invoice for this family, or create a draft
        $invoice = DB::table('invoices')->where('family_id', $family->id)
            ->whereIn('status', ['draft', 'sent', 'partial', 'overdue'])
            ->orderByDesc('id')->first();
        $invoiceLineId = null;
        if ($invoice) {
            $invoiceLineId = DB::table('invoice_lines')->insertGetId([
                'invoice_id' => $invoice->id,
                'child_id' => $child->id,
                'line_type' => 'late_pickup',
                'description' => "Late pickup: {$chargeable} min on " . $pickup->format('M j'),
                'quantity' => 1,
                'unit_amount' => $fee,
                'amount' => $fee,
            ]);
            DB::table('invoices')->where('id', $invoice->id)->update([
                'total' => DB::raw("total + {$fee}"),
                'balance_due' => DB::raw("balance_due + {$fee}"),
                'updated_at' => now(),
            ]);
        }
        $id = DB::table('late_pickup_charges')->insertGetId([
            'agency_id' => $centre->agency_id,
            'centre_id' => $centre->id,
            'child_id' => $child->id,
            'pickup_at' => $pickup,
            'close_time' => $data['close_time'],
            'minutes_late' => $minutesLate,
            'fee_amount' => $fee,
            'invoice_line_id' => $invoiceLineId,
            'notes' => $data['notes'] ?? null,
            'created_at' => now(),
        ]);

        // Notify guardians
        $gids = DB::table('guardians')->where('family_id', $family->id)->pluck('user_id');
        foreach ($gids as $gid) {
            DB::table('notifications')->insert([
                'user_id' => $gid, 'type' => 'late_pickup',
                'title' => "Late pickup fee \${$fee}",
                'body' => "{$chargeable} minute(s) late on " . $pickup->format('M j') . '. Added to your next invoice.',
                'data' => json_encode(['link' => '#billing', 'charge_id' => $id]),
                'created_at' => now(),
            ]);
        }
        return response()->json(['id' => $id, 'fee' => $fee, 'minutes_late' => $minutesLate, 'chargeable' => $chargeable], 201);
    }

    public function lateHistory(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $rows = DB::table('late_pickup_charges as lpc')
            ->join('children as c', 'c.id', '=', 'lpc.child_id')
            ->where('lpc.agency_id', $agencyId)
            ->orderByDesc('lpc.pickup_at')
            ->select('lpc.*',
                DB::raw("CONCAT(c.first_name,' ',c.last_name) as child_name"))
            ->limit(200)
            ->get();
        return response()->json(['data' => $rows]);
    }

    // =========================================================
    // Real-time room ratio compliance
    // =========================================================
    public function ratioStatus(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        // Ontario CCEYA ratios used as defaults — overridable per room later.
        // Format: maxAgeMonths => [educatorRatio, maxGroupSize]
        $standards = [
            18  => ['ratio' => 3, 'max_group' => 10, 'label' => 'Infant'],
            30  => ['ratio' => 5, 'max_group' => 15, 'label' => 'Toddler'],
            72  => ['ratio' => 8, 'max_group' => 16, 'label' => 'Preschool'],
            156 => ['ratio' => 15, 'max_group' => 30, 'label' => 'School-age'],
        ];

        $rooms = DB::table('rooms as r')
            ->join('centres as c', 'c.id', '=', 'r.centre_id')
            ->where('c.agency_id', $agencyId)
            ->select('r.id', 'r.name', 'r.centre_id', 'c.name as centre_name', 'r.capacity as licensed_capacity')
            ->get();

        $rows = $rooms->map(function ($room) use ($standards) {
            $childCount = DB::table('children')
                ->where('primary_room_id', $room->id)
                ->where('enrollment_status', 'enrolled')
                ->whereNull('deleted_at')
                ->count();
            $staffCount = DB::table('role_assignments')
                ->where('centre_id', $room->centre_id)
                ->whereIn('role', ['educator', 'centre_director'])
                ->where('active', 1)
                ->count();
            // Pick the standard for the youngest child in this room
            $oldestMonths = DB::table('children')->where('primary_room_id', $room->id)
                ->whereNull('deleted_at')
                ->select(DB::raw('MIN(TIMESTAMPDIFF(MONTH, date_of_birth, NOW())) as min_age'))
                ->value('min_age');
            $oldestMonths = (int) $oldestMonths;
            $standard = null;
            foreach ($standards as $maxMonths => $s) {
                if ($oldestMonths <= $maxMonths) { $standard = $s + ['age_band' => "0-{$maxMonths}mo"]; break; }
            }
            $standard = $standard ?? $standards[156];
            $requiredStaff = (int) ceil($childCount / max(1, $standard['ratio']));
            $compliant = $staffCount >= $requiredStaff && $childCount <= $standard['max_group'];
            return [
                'room_id' => $room->id,
                'room_name' => $room->name,
                'centre_name' => $room->centre_name,
                'standard' => $standard,
                'children_present' => $childCount,
                'staff_present' => $staffCount,
                'required_staff' => $requiredStaff,
                'compliant' => $compliant,
                'over_max_group' => $childCount > $standard['max_group'],
            ];
        });
        return response()->json(['data' => $rows]);
    }

    // =========================================================
    // Bus routes + transportation
    // =========================================================
    public function busRoutes(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $rows = DB::table('bus_routes as br')
            ->leftJoin('users as u', 'u.id', '=', 'br.driver_id')
            ->where('br.agency_id', $agencyId)
            ->select('br.*', DB::raw("CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) as driver_name"))
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function createBusRoute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => 'required|integer',
            'route_name' => 'required|string|max:120',
            'driver_id' => 'nullable|integer',
            'vehicle_label' => 'nullable|string|max:80',
        ]);
        $this->assertCentreAccess($request, (int) $data['centre_id']);
        $centre = DB::table('centres')->where('id', $data['centre_id'])->first();
        $id = DB::table('bus_routes')->insertGetId([
            'agency_id' => $centre->agency_id,
            'centre_id' => $data['centre_id'],
            'route_name' => $data['route_name'],
            'driver_id' => $data['driver_id'] ?? null,
            'vehicle_label' => $data['vehicle_label'] ?? null,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['id' => $id], 201);
    }

    public function routeChildren(Request $request, int $routeId): JsonResponse
    {
        // SECURITY (v22p96): scope the bus roster to the caller's active agency
        // (bus_routes.agency_id). Was readable by route id for any caller.
        $routeAgency = (int) DB::table('bus_routes')->where('id', $routeId)->value('agency_id');
        abort_unless($routeAgency, 404);
        abort_unless($routeAgency === (int) $this->resolveAgencyId($request), 403);
        $rows = DB::table('bus_route_children as brc')
            ->join('children as c', 'c.id', '=', 'brc.child_id')
            ->where('brc.bus_route_id', $routeId)
            ->orderBy('brc.stop_order')
            ->select('brc.*',
                DB::raw("CONCAT(c.first_name,' ',c.last_name) as child_name"))
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function addChildToRoute(Request $request, int $routeId): JsonResponse
    {
        $data = $request->validate([
            'child_id' => 'required|integer',
            'pickup_address' => 'nullable|string|max:255',
            'pickup_time' => 'nullable',
            'dropoff_time' => 'nullable',
            'stop_order' => 'nullable|integer',
        ]);
        DB::table('bus_route_children')->updateOrInsert(
            ['bus_route_id' => $routeId, 'child_id' => $data['child_id']],
            [
                'pickup_address' => $data['pickup_address'] ?? null,
                'pickup_time' => $data['pickup_time'] ?? null,
                'dropoff_time' => $data['dropoff_time'] ?? null,
                'stop_order' => $data['stop_order'] ?? 0,
                'active' => 1,
            ]
        );
        return response()->json(['status' => 'added']);
    }

    // =========================================================
    // Auto room rotation — children aging into the next band
    // =========================================================
    public function rotationCandidates(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        // Children turning 18/30/72/156 months in the next 60 days
        $thresholds = [18, 30, 72, 156];
        $now = Carbon::now();
        $rows = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('c.agency_id', $agencyId)
            ->where('ch.enrollment_status', 'enrolled')
            ->whereNull('ch.deleted_at')
            ->select('ch.id', 'ch.first_name', 'ch.last_name', 'ch.date_of_birth',
                'ch.primary_room_id', 'c.id as centre_id', 'c.name as centre_name')
            ->get();
        $candidates = [];
        foreach ($rows as $ch) {
            $dob = Carbon::parse($ch->date_of_birth);
            $ageMonths = $dob->diffInMonths($now);
            foreach ($thresholds as $t) {
                $reachAt = $dob->copy()->addMonths($t);
                $daysOut = (int) $now->diffInDays($reachAt, false);
                if ($daysOut >= 0 && $daysOut <= 60) {
                    $candidates[] = [
                        'child_id' => $ch->id,
                        'child_name' => $ch->first_name . ' ' . $ch->last_name,
                        'current_age_months' => $ageMonths,
                        'threshold' => $t,
                        'reach_date' => $reachAt->toDateString(),
                        'days_until' => $daysOut,
                        'centre_id' => $ch->centre_id,
                        'centre_name' => $ch->centre_name,
                    ];
                    break;
                }
            }
        }
        usort($candidates, fn ($a, $b) => $a['days_until'] - $b['days_until']);
        return response()->json(['data' => $candidates]);
    }

    public function planRotation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => 'required|integer',
            'to_room_id' => 'required|integer',
            'rotation_date' => 'required|date',
            'reason' => 'nullable|string|max:120',
        ]);
        $child = DB::table('children')->where('id', $data['child_id'])->first();
        abort_unless($child, 404);
        $agencyId = (int) DB::table('families')->where('id', $child->family_id)
            ->join('centres', 'centres.id', '=', 'families.centre_id')
            ->value('centres.agency_id');
        $id = DB::table('room_rotations')->insertGetId([
            'agency_id' => $agencyId,
            'child_id' => $data['child_id'],
            'from_room_id' => $child->primary_room_id,
            'to_room_id' => $data['to_room_id'],
            'rotation_date' => $data['rotation_date'],
            'reason' => $data['reason'] ?? 'age_progression',
            'status' => 'planned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // notify family
        $gids = DB::table('guardians')->where('family_id', $child->family_id)->pluck('user_id');
        foreach ($gids as $gid) {
            DB::table('notifications')->insert([
                'user_id' => $gid, 'type' => 'room_rotation',
                'title' => "{$child->first_name} moving rooms on " . Carbon::parse($data['rotation_date'])->format('M j'),
                'body' => "Your child is being moved to the next age room.",
                'data' => json_encode(['link' => '#room-rotations', 'rotation_id' => $id]),
                'created_at' => now(),
            ]);
        }
        return response()->json(['id' => $id], 201);
    }

    // helpers
    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        // SECURITY (v22p94): only honour the header if the user is platform_admin
        // or holds an active role for that exact agency (else fall back below).
        if ($activeId && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)->where(function ($w) use ($activeId) { $w->where('agency_id', $activeId)->orWhere('role', 'platform_admin'); })->exists()) return $activeId;
        // SECURITY (v22p98): a platform_admin with no valid SELECTED agency must NOT
        // fall through to their first role's agency (iLearn) — require an explicit
        // choice, else agency-scoped data leaked to a super-admin on a header-less call.
        if (DB::table('role_assignments')->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', true)->exists()) abort(400, 'Select an agency first.');
        $first = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400);
        return (int) $first;
    }

    private function assertCentreAccess(Request $request, int $centreId): void
    {
        $u = $request->user();
        $agencyId = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
        abort_unless($agencyId, 404);
        $isPlatform = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform) {
            // v22p98: scope to the agency they've switched into (was an unconditional
            // grant that let a super-admin reach any centre in any tenant).
            abort_unless($agencyId === (int) $request->header('X-Active-Agency-Id'), 403);
            return;
        }
        $hasRole = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('agency_id', $agencyId)
            ->whereIn('role', ['agency_admin', 'centre_director', 'educator'])
            ->where('active', true)->exists();
        abort_unless($hasRole, 403);
    }
}
