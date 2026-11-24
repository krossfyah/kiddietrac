<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\PickupPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The parent's pickup countdown and alarm (2026-11-09, see App\Support\PickupPlan). Only the child's own guardians.
 */
class ParentPickupPlanController extends Controller
{
    /** GET /parent/pickup-plan[?child_id=] - each of my children: check-in, pickup time, my alarm. */
    public function index(Request $request): JsonResponse
    {
        $childId = $request->filled('child_id') ? (int) $request->query('child_id') : null;

        return response()->json(['children' => PickupPlan::forUser((int) $request->user()->id, $childId)]);
    }

    /** POST /parent/pickup-plan {child_id, adjust_minutes?, alarm_on?, lead_minutes?} - my own reminder only. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'adjust_minutes' => ['sometimes', 'integer', 'between:-' . PickupPlan::ADJUST_MAX . ',' . PickupPlan::ADJUST_MAX],
            'alarm_on' => ['sometimes', 'boolean'],
            'lead_minutes' => ['sometimes', 'integer', Rule::in(PickupPlan::LEADS)],
        ]);
        $userId = (int) $request->user()->id;
        $k = PickupPlan::children($userId, (int) $data['child_id'])->first();
        if (! $k) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $row = DB::table('pickup_alarms')->where('user_id', $userId)->where('child_id', (int) $k->id)->first();
        $set = ['updated_at' => now()];
        if (array_key_exists('adjust_minutes', $data)) {
            $set['adjust_minutes'] = (int) $data['adjust_minutes'];
            $set['adjust_date'] = AgencyTime::today((int) $k->agency_id);
        }
        if (array_key_exists('alarm_on', $data)) {
            $set['alarm_on'] = (bool) $data['alarm_on'];
        }
        if (array_key_exists('lead_minutes', $data)) {
            $set['lead_minutes'] = (int) $data['lead_minutes'];
        }
        if ($row) {
            DB::table('pickup_alarms')->where('id', $row->id)->update($set);
        } else {
            DB::table('pickup_alarms')->insert($set + ['user_id' => $userId, 'child_id' => (int) $k->id,
                'lead_minutes' => PickupPlan::DEFAULT_LEAD, 'created_at' => now()]);
        }

        return $this->index(tap($request, fn ($r) => $r->query->set('child_id', (string) $k->id)));
    }
}
