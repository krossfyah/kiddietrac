<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p57 — Drip campaigns + curriculum library.
 */
final class GrowthController extends Controller
{
    // ===== Drip campaigns (extends marketing_campaigns) =====
    public function listDrip(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $rows = DB::table('marketing_campaigns')
            ->where('agency_id', $agencyId)
            ->whereNotNull('trigger_event')
            ->orderByDesc('created_at')->get();
        return response()->json(['data' => $rows]);
    }

    public function createDrip(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'subject' => 'required|string|max:200',
            'body_html' => 'required|string',
            'trigger_event' => 'required|string|in:tour_booked,enrollment_started,enrollment_complete,birthday,inactivity_30d',
            'trigger_delay_days' => 'required|integer|min:0|max:365',
            'centre_id' => 'nullable|integer',
        ]);
        $agencyId = $this->resolveAgencyId($request);
        $id = DB::table('marketing_campaigns')->insertGetId([
            'agency_id' => $agencyId,
            'centre_id' => $data['centre_id'] ?? null,
            'created_by_user_id' => $request->user()->id,
            'title' => $data['title'],
            'subject' => $data['subject'],
            'body_html' => $data['body_html'],
            'audience' => 'drip-' . $data['trigger_event'],
            'channel' => 'email',
            'status' => 'active',
            'trigger_event' => $data['trigger_event'],
            'trigger_delay_days' => $data['trigger_delay_days'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['id' => $id], 201);
    }

    public function dripStats(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $campaignIds = DB::table('marketing_campaigns')->where('agency_id', $agencyId)
            ->whereNotNull('trigger_event')->pluck('id');
        $sent = DB::table('drip_sends')->whereIn('campaign_id', $campaignIds);
        return response()->json([
            'campaigns' => $campaignIds->count(),
            'queued' => (clone $sent)->where('status', 'queued')->count(),
            'sent' => (clone $sent)->where('status', 'sent')->count(),
            'failed' => (clone $sent)->where('status', 'failed')->count(),
        ]);
    }

    // ===== Curriculum library =====
    public function listCurriculum(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $rows = DB::table('curriculum_templates as ct')
            ->leftJoin('users as u', 'u.id', '=', 'ct.created_by_user_id')
            ->where(function ($q) use ($agencyId) {
                $q->where('ct.agency_id', $agencyId)->orWhere('ct.visibility', 'platform');
            })
            ->orderByDesc('ct.is_featured')->orderBy('ct.title')
            ->select('ct.*', DB::raw("CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) as author_name"))
            ->get();
        $rows->transform(function ($r) {
            $r->activities = $r->activities ? json_decode($r->activities, true) : [];
            $r->materials = $r->materials ? json_decode($r->materials, true) : [];
            $r->hdlh_domains = $r->hdlh_domains ? json_decode($r->hdlh_domains, true) : [];
            return $r;
        });
        return response()->json(['data' => $rows]);
    }

    public function createCurriculum(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => 'nullable|integer',
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:5000',
            'age_band' => 'nullable|string|max:40',
            'duration_minutes' => 'nullable|integer|min:5|max:240',
            'materials' => 'nullable|array',
            'hdlh_domains' => 'nullable|array',
            'activities' => 'nullable|array',
            'visibility' => 'nullable|in:private,centre,agency,platform',
        ]);
        $agencyId = $this->resolveAgencyId($request);
        $id = DB::table('curriculum_templates')->insertGetId([
            'agency_id' => $agencyId,
            'centre_id' => $data['centre_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'age_band' => $data['age_band'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'materials' => isset($data['materials']) ? json_encode($data['materials']) : null,
            'hdlh_domains' => isset($data['hdlh_domains']) ? json_encode($data['hdlh_domains']) : null,
            'activities' => isset($data['activities']) ? json_encode($data['activities']) : null,
            'visibility' => $data['visibility'] ?? 'agency',
            'created_by_user_id' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['id' => $id], 201);
    }

    public function useCurriculum(Request $request, int $id): JsonResponse
    {
        DB::table('curriculum_templates')->where('id', $id)
            ->update(['use_count' => DB::raw('use_count + 1'), 'updated_at' => now()]);
        $row = DB::table('curriculum_templates')->where('id', $id)->first();
        return response()->json(['data' => $row]);
    }

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
}
