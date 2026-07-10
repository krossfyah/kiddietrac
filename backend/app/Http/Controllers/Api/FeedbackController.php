<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p55 — Parent feedback ratings (1-5 stars + optional comment).
 *   GET  /feedback                 director: list all + summary
 *   POST /feedback                 parent: submit
 *   GET  /feedback/mine            parent: own history
 */
final class FeedbackController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $isStaff = DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])
            ->where('active', 1)->exists();
        if (!$isStaff) {
            return $this->mine($request);
        }
        $rows = DB::table('parent_feedback as pf')
            ->leftJoin('families as f', 'f.id', '=', 'pf.family_id')
            ->join('centres as c', 'c.id', '=', 'pf.centre_id')
            ->where('c.agency_id', $agencyId)
            ->orderByDesc('pf.created_at')
            ->select('pf.*', 'f.family_name')
            ->limit(200)
            ->get();
        $breakdown = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($rows as $r) { $breakdown[(int) $r->rating] = ($breakdown[(int) $r->rating] ?? 0) + 1; }
        $summary = [
            'count' => $rows->count(),
            'avg' => $rows->count() ? round($rows->avg('rating'), 2) : 0,
            'breakdown' => $breakdown,
        ];
        return response()->json(['data' => $rows, 'summary' => $summary]);
    }

    public function mine(Request $request): JsonResponse
    {
        $u = $request->user();
        $familyIds = DB::table('guardians')->where('user_id', $u->id)->pluck('family_id');
        $rows = DB::table('parent_feedback')
            ->whereIn('family_id', $familyIds)
            ->orderByDesc('created_at')->limit(50)->get();
        return response()->json(['data' => $rows]);
    }

    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'category' => 'nullable|string|max:60',
            'comment' => 'nullable|string|max:2000',
        ]);
        $u = $request->user();
        $familyId = DB::table('guardians')->where('user_id', $u->id)->value('family_id');
        $family = $familyId ? DB::table('families')->where('id', $familyId)->first() : null;
        $id = DB::table('parent_feedback')->insertGetId([
            'family_id' => $familyId,
            'centre_id' => $family ? $family->centre_id : null,
            'user_id' => $u->id,
            'rating' => $data['rating'],
            'category' => $data['category'] ?? 'general',
            'comment' => $data['comment'] ?? null,
            'created_at' => now(),
        ]);
        // notify directors of low ratings
        if ($data['rating'] <= 3 && $family) {
            $directorIds = DB::table('role_assignments')
                ->whereIn('role', ['centre_director', 'agency_admin'])
                ->where('centre_id', $family->centre_id)
                ->where('active', 1)
                ->pluck('user_id')->unique();
            foreach ($directorIds as $did) {
                DB::table('notifications')->insert([
                    'user_id' => $did, 'type' => 'feedback',
                    'title' => "{$data['rating']}-star feedback from " . ($family->family_name ?? 'a parent'),
                    'body' => substr((string) ($data['comment'] ?? ''), 0, 200),
                    'data' => json_encode(['link' => '#feedback', 'feedback_id' => $id]),
                    'created_at' => now(),
                ]);
            }
        }
        return response()->json(['id' => $id], 201);
    }

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
}
