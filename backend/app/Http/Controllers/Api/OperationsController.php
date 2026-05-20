<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * v22p53 — Operations: drop-off signatures + meal planning +
 * allergy alerts + field trips + substitutes + inspection checklist.
 * Single controller because each method is small and they share
 * the same agency/centre scoping helpers.
 */
final class OperationsController extends Controller
{
    // =========================================================
    // (1) Drop-off / pickup signature capture from kiosk
    // =========================================================
    public function captureSignature(Request $request, string $kioskToken): JsonResponse
    {
        $centre = DB::table('centres')->where('kiosk_token', $kioskToken)->first();
        abort_unless($centre && $centre->kiosk_enabled, 404, 'Kiosk not enabled');
        $data = $request->validate([
            'child_id' => 'required|integer',
            'event_type' => 'required|in:check_in,check_out',
            'signature_data' => 'required|string|max:200000',
            'parent_name' => 'nullable|string|max:120',
            'parent_phone' => 'nullable|string|max:40',
            'notes' => 'nullable|string|max:1000',
        ]);
        $child = DB::table('children as c')
            ->where('c.id', $data['child_id'])
            ->whereNull('c.deleted_at')
            ->first();
        abort_unless($child, 404);
        // ensure the child belongs to this centre's agency
        $family = DB::table('families')->where('id', $child->family_id)->first();
        abort_unless($family && $family->centre_id === (int) $centre->id, 403, 'Child not at this centre');

        $id = DB::table('kiosk_signatures')->insertGetId([
            'centre_id' => $centre->id,
            'child_id' => $data['child_id'],
            'event_type' => $data['event_type'],
            'signature_data' => $data['signature_data'],
            'parent_name' => $data['parent_name'] ?? null,
            'parent_phone' => $data['parent_phone'] ?? null,
            'notes' => $data['notes'] ?? null,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
        return response()->json(['id' => $id, 'status' => 'recorded'], 201);
    }

    public function listSignatures(Request $request, int $childId): JsonResponse
    {
        $rows = DB::table('kiosk_signatures')
            ->where('child_id', $childId)
            ->orderByDesc('occurred_at')
            ->limit(60)
            ->get(['id', 'event_type', 'parent_name', 'occurred_at', 'notes']);
        return response()->json(['data' => $rows]);
    }

    // =========================================================
    // (2) Meal planning
    // =========================================================
    public function getMenuWeek(Request $request): JsonResponse
    {
        $centreId = (int) $request->query('centre_id', 0);
        abort_unless($centreId, 400, 'centre_id required');
        $weekStart = Carbon::parse($request->query('week_start', Carbon::now()->startOfWeek()->toDateString()))->toDateString();
        $week = DB::table('menu_weeks')
            ->where('centre_id', $centreId)
            ->where('week_start', $weekStart)
            ->first();
        if (!$week) return response()->json(['data' => null, 'week_start' => $weekStart]);
        $items = DB::table('menu_items')->where('menu_week_id', $week->id)
            ->orderBy('day_of_week')->orderBy('meal_type')->get();
        return response()->json(['data' => $week, 'items' => $items, 'week_start' => $weekStart]);
    }

    public function saveMenuWeek(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => 'required|integer',
            'week_start' => 'required|date',
            'status' => 'required|in:draft,published,archived',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array',
            'items.*.day_of_week' => 'required|integer|between:1,7',
            'items.*.meal_type' => 'required|in:breakfast,morning_snack,lunch,afternoon_snack,dinner',
            'items.*.name' => 'required|string|max:255',
            'items.*.description' => 'nullable|string|max:1000',
            'items.*.allergens' => 'nullable|string|max:500',
        ]);
        $this->assertCentreAccess($request, (int) $data['centre_id']);
        $week = DB::table('menu_weeks')
            ->where('centre_id', $data['centre_id'])
            ->where('week_start', $data['week_start'])
            ->first();
        $weekPayload = [
            'centre_id' => $data['centre_id'],
            'week_start' => $data['week_start'],
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
            'created_by_id' => $request->user()->id,
            'published_at' => $data['status'] === 'published' ? now() : null,
            'updated_at' => now(),
        ];
        if ($week) {
            DB::table('menu_weeks')->where('id', $week->id)->update($weekPayload);
            $weekId = $week->id;
            DB::table('menu_items')->where('menu_week_id', $weekId)->delete();
        } else {
            $weekPayload['created_at'] = now();
            $weekId = DB::table('menu_weeks')->insertGetId($weekPayload);
        }
        foreach ($data['items'] as $it) {
            DB::table('menu_items')->insert([
                'menu_week_id' => $weekId,
                'day_of_week' => $it['day_of_week'],
                'meal_type' => $it['meal_type'],
                'name' => $it['name'],
                'description' => $it['description'] ?? null,
                'allergens' => $it['allergens'] ?? null,
            ]);
        }
        if ($data['status'] === 'published') {
            $this->notifyMenuPublished((int) $data['centre_id'], $data['week_start']);
        }
        return response()->json(['id' => $weekId, 'status' => $data['status']]);
    }

    private function notifyMenuPublished(int $centreId, string $weekStart): void
    {
        $guardianIds = DB::table('guardians as g')
            ->join('families as f', 'f.id', '=', 'g.family_id')
            ->where('f.centre_id', $centreId)
            ->pluck('g.user_id')->unique();
        foreach ($guardianIds as $gid) {
            DB::table('notifications')->insert([
                'user_id' => $gid,
                'type' => 'menu',
                'title' => 'This week\'s menu is published',
                'body' => 'Week of ' . Carbon::parse($weekStart)->format('M j'),
                'data' => json_encode(['link' => '#menu', 'centre_id' => $centreId, 'week_start' => $weekStart]),
                'created_at' => now(),
            ]);
        }
    }

    // =========================================================
    // (3) Allergy / dietary alert digest for Today screen
    // =========================================================
    public function allergyAlerts(Request $request): JsonResponse
    {
        $centreId = (int) $request->query('centre_id', 0);
        $q = DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->whereNull('c.deleted_at')
            ->whereNull('f.deleted_at')
            ->where(function ($q) {
                $q->where('c.allergies', '!=', '')
                  ->orWhere('c.health_alerts', '!=', '')
                  ->orWhere('c.dietary_restrictions', '!=', '');
            })
            ->select('c.id', 'c.first_name', 'c.last_name', 'c.allergies', 'c.health_alerts', 'c.dietary_restrictions', 'c.enrollment_status', 'c.family_id', 'f.centre_id');
        if ($centreId) $q->where('f.centre_id', $centreId);
        $rows = $q->get();
        $rows->transform(function ($r) {
            $tags = [];
            $decode = function ($raw, $kind) {
                if (empty($raw)) return [];
                if (is_array($raw)) $arr = $raw;
                else { $arr = json_decode($raw, true); if (!is_array($arr)) return [['kind' => $kind, 'label' => (string) $raw, 'severity' => 'note']]; }
                $out = [];
                foreach ($arr as $entry) {
                    if (is_string($entry)) { $out[] = ['kind' => $kind, 'label' => $entry, 'severity' => 'note']; continue; }
                    $label = $entry['allergen'] ?? $entry['restriction'] ?? $entry['alert'] ?? '';
                    $sev = $entry['severity'] ?? 'note';
                    $reaction = $entry['reaction'] ?? null;
                    $epipen = !empty($entry['epipen_required']) ? ' (EpiPen' . (!empty($entry['epipen_location']) ? ' @ ' . $entry['epipen_location'] : '') . ')' : '';
                    $out[] = [
                        'kind' => $kind,
                        'label' => $label . ($reaction ? ' — ' . $reaction : '') . $epipen,
                        'severity' => $sev,
                    ];
                }
                return $out;
            };
            $tags = array_merge(
                $decode($r->allergies, 'allergy'),
                $decode($r->health_alerts, 'health'),
                $decode($r->dietary_restrictions, 'dietary')
            );
            $r->tags = $tags;
            return $r;
        });
        return response()->json(['data' => $rows, 'count' => $rows->count()]);
    }

    // =========================================================
    // (4) Field trips + permission slips
    // =========================================================
    public function listFieldTrips(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $rows = DB::table('field_trips as ft')
            ->join('centres as c', 'c.id', '=', 'ft.centre_id')
            ->where('ft.agency_id', $agencyId)
            ->orderByDesc('ft.trip_date')
            ->select('ft.*', 'c.name as centre_name')
            ->limit(100)
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function createFieldTrip(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => 'required|integer',
            'title' => 'required|string|max:200',
            'destination' => 'required|string|max:255',
            'trip_date' => 'required|date',
            'depart_time' => 'nullable',
            'return_time' => 'nullable',
            'description' => 'nullable|string|max:5000',
            'transport_method' => 'nullable|string|max:60',
            'staff_lead_id' => 'nullable|integer',
            'cost_per_child' => 'nullable|numeric|min:0',
            'permission_deadline' => 'nullable|date',
            'child_ids' => 'required|array',
            'child_ids.*' => 'integer',
        ]);
        $this->assertCentreAccess($request, (int) $data['centre_id']);
        $agencyId = (int) DB::table('centres')->where('id', $data['centre_id'])->value('agency_id');
        $tripId = DB::table('field_trips')->insertGetId([
            'agency_id' => $agencyId,
            'centre_id' => $data['centre_id'],
            'title' => $data['title'],
            'destination' => $data['destination'],
            'trip_date' => $data['trip_date'],
            'depart_time' => $data['depart_time'] ?? null,
            'return_time' => $data['return_time'] ?? null,
            'description' => $data['description'] ?? null,
            'transport_method' => $data['transport_method'] ?? null,
            'staff_lead_id' => $data['staff_lead_id'] ?? null,
            'cost_per_child' => $data['cost_per_child'] ?? 0,
            'permission_deadline' => $data['permission_deadline'] ?? null,
            'created_by_id' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($data['child_ids'] as $childId) {
            DB::table('field_trip_permissions')->insert([
                'field_trip_id' => $tripId,
                'child_id' => $childId,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $guardianIds = DB::table('guardians')
                ->where('family_id', DB::table('children')->where('id', $childId)->value('family_id'))
                ->pluck('user_id');
            foreach ($guardianIds as $gid) {
                DB::table('notifications')->insert([
                    'user_id' => $gid,
                    'type' => 'field_trip',
                    'title' => "Permission slip: {$data['title']}",
                    'body' => "Trip on " . Carbon::parse($data['trip_date'])->format('M j') . " to " . $data['destination'],
                    'data' => json_encode(['link' => '#field-trips', 'trip_id' => $tripId]),
                    'created_at' => now(),
                ]);
            }
        }
        return response()->json(['id' => $tripId], 201);
    }

    public function listPermissionsForTrip(Request $request, int $tripId): JsonResponse
    {
        $rows = DB::table('field_trip_permissions as p')
            ->join('children as c', 'c.id', '=', 'p.child_id')
            ->where('p.field_trip_id', $tripId)
            ->select('p.*', 'c.first_name', 'c.last_name')
            ->get();
        $counts = [
            'total' => $rows->count(),
            'approved' => $rows->where('status', 'approved')->count(),
            'denied' => $rows->where('status', 'denied')->count(),
            'pending' => $rows->where('status', 'pending')->count(),
        ];
        return response()->json(['data' => $rows, 'counts' => $counts]);
    }

    public function respondToPermission(Request $request, int $permissionId): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:approved,denied',
            'signature_data' => 'nullable|string|max:200000',
            'notes' => 'nullable|string|max:1000',
        ]);
        $perm = DB::table('field_trip_permissions')->where('id', $permissionId)->first();
        abort_unless($perm, 404);
        $userFamilyIds = DB::table('guardians')->where('user_id', $request->user()->id)->pluck('family_id')->all();
        $childFamilyId = DB::table('children')->where('id', $perm->child_id)->value('family_id');
        abort_unless(in_array($childFamilyId, $userFamilyIds), 403, 'Not authorised for this child');
        DB::table('field_trip_permissions')->where('id', $permissionId)->update([
            'status' => $data['status'],
            'signature_data' => $data['signature_data'] ?? null,
            'notes' => $data['notes'] ?? null,
            'guardian_user_id' => $request->user()->id,
            'responded_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['status' => $data['status']]);
    }

    // =========================================================
    // (5) Substitute teacher pool + request
    // =========================================================
    public function listSubstitutes(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $rows = DB::table('substitute_pool as sp')
            ->join('users as u', 'u.id', '=', 'sp.user_id')
            ->where('sp.agency_id', $agencyId)
            ->where('sp.active', 1)
            ->orderBy('sp.contact_priority')
            ->select('sp.*', DB::raw("CONCAT(u.first_name, ' ', u.last_name) as user_name"), 'u.email as user_email', 'u.phone as user_phone')
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function upsertSubstitute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer',
            'qualifications' => 'nullable|string|max:2000',
            'availability_notes' => 'nullable|string|max:1000',
            'contact_priority' => 'nullable|integer|min:1|max:10',
            'active' => 'nullable|boolean',
            'centres' => 'nullable|array',
        ]);
        $agencyId = $this->resolveAgencyId($request);
        $existing = DB::table('substitute_pool')
            ->where('agency_id', $agencyId)
            ->where('user_id', $data['user_id'])
            ->first();
        $payload = [
            'agency_id' => $agencyId,
            'user_id' => $data['user_id'],
            'qualifications' => $data['qualifications'] ?? null,
            'availability_notes' => $data['availability_notes'] ?? null,
            'contact_priority' => $data['contact_priority'] ?? 5,
            'active' => $data['active'] ?? true,
            'centres' => isset($data['centres']) ? json_encode($data['centres']) : null,
            'updated_at' => now(),
        ];
        if ($existing) {
            DB::table('substitute_pool')->where('id', $existing->id)->update($payload);
            return response()->json(['id' => $existing->id, 'status' => 'updated']);
        }
        $payload['created_at'] = now();
        $id = DB::table('substitute_pool')->insertGetId($payload);
        return response()->json(['id' => $id, 'status' => 'created'], 201);
    }

    public function createSubRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => 'required|integer',
            'shift_date' => 'required|date',
            'shift_start' => 'required',
            'shift_end' => 'required',
            'reason' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:2000',
        ]);
        $this->assertCentreAccess($request, (int) $data['centre_id']);
        $agencyId = (int) DB::table('centres')->where('id', $data['centre_id'])->value('agency_id');
        $id = DB::table('substitute_requests')->insertGetId([
            'agency_id' => $agencyId,
            'centre_id' => $data['centre_id'],
            'requested_by_id' => $request->user()->id,
            'shift_date' => $data['shift_date'],
            'shift_start' => $data['shift_start'],
            'shift_end' => $data['shift_end'],
            'reason' => $data['reason'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // notify pool members
        $subIds = DB::table('substitute_pool')
            ->where('agency_id', $agencyId)->where('active', 1)
            ->pluck('user_id');
        foreach ($subIds as $uid) {
            DB::table('notifications')->insert([
                'user_id' => $uid,
                'type' => 'sub_request',
                'title' => 'New substitute request',
                'body' => 'Shift on ' . Carbon::parse($data['shift_date'])->format('M j') . ' ' . substr($data['shift_start'], 0, 5) . '-' . substr($data['shift_end'], 0, 5),
                'data' => json_encode(['link' => '#substitutes', 'request_id' => $id]),
                'created_at' => now(),
            ]);
        }
        return response()->json(['id' => $id, 'notified' => $subIds->count()], 201);
    }

    public function listSubRequests(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $rows = DB::table('substitute_requests as sr')
            ->join('centres as c', 'c.id', '=', 'sr.centre_id')
            ->leftJoin('users as u', 'u.id', '=', 'sr.assigned_user_id')
            ->where('sr.agency_id', $agencyId)
            ->orderByDesc('sr.shift_date')
            ->select('sr.*', 'c.name as centre_name', DB::raw("CONCAT(u.first_name, ' ', u.last_name) as assigned_name"))
            ->limit(100)
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function claimSubRequest(Request $request, int $id): JsonResponse
    {
        $req = DB::table('substitute_requests')->where('id', $id)->first();
        abort_unless($req, 404);
        abort_unless($req->status === 'open', 422, 'Already filled');
        DB::table('substitute_requests')->where('id', $id)->update([
            'assigned_user_id' => $request->user()->id,
            'status' => 'filled',
            'filled_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('notifications')->insert([
            'user_id' => $req->requested_by_id,
            'type' => 'sub_request',
            'title' => 'Substitute claimed',
            'body' => 'Your shift request was picked up by a substitute.',
            'data' => json_encode(['link' => '#substitutes', 'request_id' => $id]),
            'created_at' => now(),
        ]);
        return response()->json(['status' => 'filled']);
    }

    // =========================================================
    // (6) Provincial inspection prep checklist
    // =========================================================
    public function getInspectionChecklist(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $centreId = (int) $request->query('centre_id', 0) ?: null;
        $q = DB::table('inspection_checklist_items')
            ->where('agency_id', $agencyId)
            ->orderBy('category')
            ->orderBy('display_order');
        if ($centreId) $q->where(function ($q) use ($centreId) {
            $q->where('centre_id', $centreId)->orWhereNull('centre_id');
        });
        $rows = $q->get();
        // Seed defaults if empty
        if ($rows->isEmpty()) {
            $this->seedInspectionTemplate($agencyId);
            $rows = DB::table('inspection_checklist_items')
                ->where('agency_id', $agencyId)
                ->orderBy('category')->orderBy('display_order')->get();
        }
        $grouped = $rows->groupBy('category');
        return response()->json(['data' => $grouped, 'total' => $rows->count(), 'done' => $rows->where('status', 'pass')->count()]);
    }

    public function updateChecklistItem(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:pending,pass,fail,na',
            'notes' => 'nullable|string|max:5000',
            'evidence_url' => 'nullable|string|max:500',
        ]);
        DB::table('inspection_checklist_items')->where('id', $id)->update($data + [
            'reviewed_by_id' => $request->user()->id,
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['status' => $data['status']]);
    }

    private function seedInspectionTemplate(int $agencyId): void
    {
        $template = [
            'Documentation' => [
                'Licence visibly posted in the centre',
                'Centre handbook current and matches policies in practice',
                'Emergency contacts list current for every child',
                'Incident-report log up to date with no overdue follow-ups',
                'Daily attendance records signed for last 90 days',
            ],
            'Staff' => [
                'All staff Vulnerable Sector Search current (within 5 years)',
                'First Aid + CPR certificates current for every required role',
                'Food handler certification for kitchen staff current',
                'Staff:child ratios documented and observed across the day',
                'Substitute teacher list current with credentials on file',
            ],
            'Health & safety' => [
                'Anaphylaxis policy posted and signed by all staff',
                'Medication storage locked, labelled, and current',
                'Fire-evacuation drill log shows monthly drill',
                'First-aid kits stocked in each room',
                'Pesticide / cleaning chemicals stored out of children\'s reach',
            ],
            'Food & nutrition' => [
                'Weekly menu posted and follows Canada\'s Food Guide',
                'Allergy list visible in kitchen and matches child records',
                'Food temperature log current',
                'Refrigerator temperature log current',
                'Kitchen sanitation routine documented and followed',
            ],
            'Indoor environment' => [
                'Outlets covered and cords secured in every room',
                'Toys and equipment clean and intact',
                'Bathrooms accessible, clean, and stocked',
                'Quiet / rest space available with individual mats labelled per child',
                'Indoor temperature within licensed range',
            ],
            'Outdoor environment' => [
                'Outdoor play area fenced and gates secured',
                'Play equipment inspected within last 90 days',
                'Surface materials adequate under climbing equipment',
                'Outdoor first-aid kit present',
                'Sunscreen permission slips on file for current season',
            ],
            'Programming' => [
                'Lesson plans posted for current week',
                'HDLH observations recorded for every child this month',
                'Cultural / inclusion practices documented in lesson plans',
                'Children\'s artwork and reflections visible on walls',
                'Daily activity schedule posted and followed',
            ],
            'Records' => [
                'Immunization records on file for every enrolled child',
                'Allergy / dietary cards on file and matching kitchen list',
                'Custody / pickup-authorisation forms current',
                'Photo-release consent forms on file',
                'Child-specific health-care plans for children with conditions',
            ],
        ];
        $order = 0;
        foreach ($template as $cat => $items) {
            foreach ($items as $i => $text) {
                DB::table('inspection_checklist_items')->insert([
                    'agency_id' => $agencyId,
                    'centre_id' => null,
                    'category' => $cat,
                    'item_text' => $text,
                    'status' => 'pending',
                    'is_template' => 1,
                    'display_order' => ++$order,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    // =========================================================
    // Helpers
    // =========================================================
    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($activeId) return $activeId;
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
        $isPlatform = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform) return;
        $agencyId = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
        $hasRole = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('agency_id', $agencyId)
            ->whereIn('role', ['agency_admin', 'centre_director', 'educator'])
            ->where('active', true)->exists();
        abort_unless($hasRole, 403);
    }
}
