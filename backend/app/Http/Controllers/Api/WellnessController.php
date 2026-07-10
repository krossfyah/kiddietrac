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
 * v22p63 — Wellness pre-arrival screening.
 * Parents submit a daily yes/no symptom check before drop-off. Staff
 * see the result on the Today screen + can block kiosk check-in if
 * the result is 'block'.
 */
final class WellnessController extends Controller
{
    use ResolvesCentreContext;

    public function todayForChild(Request $request, int $childId): JsonResponse
    {
        abort_unless($this->canAccessChildId($request->user(), $childId), 403);
        $today = Carbon::today()->toDateString();
        $row = DB::table('wellness_screenings')
            ->where('child_id', $childId)
            ->where('screening_date', $today)
            ->first();
        return response()->json(['data' => $row, 'date' => $today]);
    }

    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => 'required|integer',
            'fever' => 'required|boolean',
            'cough' => 'required|boolean',
            'runny_nose' => 'required|boolean',
            'vomiting' => 'required|boolean',
            'diarrhea' => 'required|boolean',
            'rash' => 'required|boolean',
            'exposure' => 'required|boolean',
            'notes' => 'nullable|string|max:1000',
        ]);
        // SECURITY (v22p94): only the child's guardians/centre staff may submit a
        // screening (it can set result=block and bar the child's attendance).
        abort_unless($this->canAccessChildId($request->user(), (int) $data['child_id']), 403);

        // Decide the result. Public-health guidance: any of fever/vomiting/
        // diarrhea blocks attendance; any of cough/runny_nose flags review.
        $block = $data['fever'] || $data['vomiting'] || $data['diarrhea'] || $data['exposure'];
        $review = $data['cough'] || $data['runny_nose'] || $data['rash'];
        $result = $block ? 'block' : ($review ? 'review' : 'cleared');

        $today = Carbon::today()->toDateString();
        DB::table('wellness_screenings')->updateOrInsert(
            ['child_id' => $data['child_id'], 'screening_date' => $today],
            [
                'submitted_by_user_id' => $request->user()->id,
                'fever' => $data['fever'],
                'cough' => $data['cough'],
                'runny_nose' => $data['runny_nose'],
                'vomiting' => $data['vomiting'],
                'diarrhea' => $data['diarrhea'],
                'rash' => $data['rash'],
                'exposure' => $data['exposure'],
                'notes' => $data['notes'] ?? null,
                'result' => $result,
                'created_at' => now(),
            ]
        );

        // Notify centre staff if blocked/review
        if ($result !== 'cleared') {
            $child = DB::table('children')->where('id', $data['child_id'])->first();
            $family = DB::table('families')->where('id', $child->family_id)->first();
            $staff = DB::table('role_assignments')->where('centre_id', $family->centre_id)
                ->whereIn('role', ['educator', 'centre_director', 'agency_admin'])
                ->where('active', 1)->pluck('user_id')->unique();
            $title = $result === 'block'
                ? "🚫 {$child->first_name}: do not admit today"
                : "⚠ {$child->first_name}: review symptoms before admit";
            foreach ($staff as $sid) {
                DB::table('notifications')->insert([
                    'user_id' => $sid, 'type' => 'wellness',
                    'title' => $title,
                    'body' => 'Parent reported: ' . trim(implode(', ', array_keys(array_filter([
                        'fever' => $data['fever'], 'cough' => $data['cough'], 'runny_nose' => $data['runny_nose'],
                        'vomiting' => $data['vomiting'], 'diarrhea' => $data['diarrhea'], 'rash' => $data['rash'],
                        'exposure' => $data['exposure'],
                    ])))),
                    'data' => json_encode(['link' => '#wellness', 'child_id' => $data['child_id']]),
                    'created_at' => now(),
                ]);
            }
        }
        return response()->json(['result' => $result, 'date' => $today]);
    }

    public function todayDigest(Request $request): JsonResponse
    {
        $centreId = (int) $request->query('centre_id', 0);
        abort_unless($centreId, 400);
        // SECURITY (v22p96): wellness screenings are medical PII. The endpoint took
        // centre_id straight from the query with no authorization — any caller (and
        // a switched platform_admin) could read any centre's screenings. Now staff
        // of this centre only, or a platform_admin scoped to its agency.
        $u = $request->user();
        if ($this->isPlatformAdminUser($u)) {
            $centreAgency = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
            abort_unless($centreAgency && $centreAgency === (int) $this->resolveAgencyId($request), 403);
        } else {
            abort_unless($this->authorizeCentreAccess($u, $centreId), 403);
        }
        $today = Carbon::today()->toDateString();
        $rows = DB::table('wellness_screenings as ws')
            ->join('children as ch', 'ch.id', '=', 'ws.child_id')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->where('f.centre_id', $centreId)
            ->where('ws.screening_date', $today)
            ->select('ws.*',
                DB::raw("CONCAT(ch.first_name,' ',ch.last_name) as child_name"),
                'ch.first_name')
            ->get();
        $by = ['cleared' => 0, 'review' => 0, 'block' => 0];
        foreach ($rows as $r) $by[$r->result] = ($by[$r->result] ?? 0) + 1;
        $totalEnrolled = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->where('f.centre_id', $centreId)
            ->where('ch.enrollment_status', 'enrolled')
            ->whereNull('ch.deleted_at')->count();
        return response()->json([
            'date' => $today,
            'enrolled_count' => $totalEnrolled,
            'submitted_count' => $rows->count(),
            'not_submitted_count' => max(0, $totalEnrolled - $rows->count()),
            'counts' => $by,
            'data' => $rows,
        ]);
    }
}
