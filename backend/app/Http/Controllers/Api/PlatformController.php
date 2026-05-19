<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * v22p22 — Cross-agency platform-admin tooling. Every method assumes the
 * caller is platform_admin (gated by route middleware role:platform_admin
 * + EnsureRole's superset bypass shipped in v22p21).
 */
final class PlatformController extends Controller
{
    /**
     * GET /api/v1/platform/overview
     * Returns total counts across every active agency in the system.
     */
    public function overview(Request $request): JsonResponse
    {
        $agencies = DB::table('agencies')->whereNull('deleted_at')->count();
        $centres  = DB::table('centres')->whereNull('deleted_at')->count();
        $rooms    = DB::table('rooms')->count();
        $families = DB::table('families')->whereNull('deleted_at')->count();
        $children = DB::table('children')->whereNull('deleted_at')->count();
        $staff    = DB::table('role_assignments')
            ->whereIn('role', ['agency_admin', 'centre_director', 'educator'])
            ->where('active', true)
            ->distinct()
            ->count('user_id');
        $guardians = DB::table('guardians')->distinct()->count('user_id');

        // Plan MRR — sum each active agency's plan_amount_cents (in cents).
        $mrrCents = (int) DB::table('agencies')
            ->whereNull('deleted_at')
            ->where('billing_status', 'active')
            ->sum('plan_amount_cents');

        // Recent agency signups + churn — last 30 days
        $thirtyDaysAgo = now()->subDays(30);
        $newAgencies30d = DB::table('agencies')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->whereNull('deleted_at')
            ->count();
        $cancelledAgencies30d = DB::table('agencies')
            ->where('cancelled_at', '>=', $thirtyDaysAgo)
            ->count();

        return response()->json([
            'totals' => [
                'agencies' => $agencies,
                'centres'  => $centres,
                'rooms'    => $rooms,
                'families' => $families,
                'children' => $children,
                'staff'    => $staff,
                'guardians' => $guardians,
                'mrr_cents' => $mrrCents,
                'mrr_dollars' => $mrrCents / 100,
            ],
            'recent_30d' => [
                'new_agencies' => $newAgencies30d,
                'cancelled_agencies' => $cancelledAgencies30d,
                'net' => $newAgencies30d - $cancelledAgencies30d,
            ],
        ]);
    }

    /**
     * GET /api/v1/platform/agencies
     * List every agency with per-tenant stats.
     */
    public function listAgencies(Request $request): JsonResponse
    {
        $agencies = DB::table('agencies')->whereNull('deleted_at')->orderBy('name')->get();
        $agencyIds = $agencies->pluck('id')->all();

        $centresPerAgency = DB::table('centres')
            ->whereIn('agency_id', $agencyIds)
            ->whereNull('deleted_at')
            ->select('agency_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('agency_id')
            ->pluck('cnt', 'agency_id');

        $centreIdsPerAgency = DB::table('centres')
            ->whereIn('agency_id', $agencyIds)
            ->whereNull('deleted_at')
            ->select('agency_id', 'id')
            ->get()
            ->groupBy('agency_id')
            ->map(fn ($g) => $g->pluck('id')->all());

        $result = $agencies->map(function ($a) use ($centresPerAgency, $centreIdsPerAgency) {
            $cids = $centreIdsPerAgency[$a->id] ?? [];
            $familyCount = empty($cids) ? 0 : DB::table('families')->whereIn('centre_id', $cids)->whereNull('deleted_at')->count();
            $childCount  = empty($cids) ? 0 : DB::table('children')
                ->join('families', 'families.id', '=', 'children.family_id')
                ->whereIn('families.centre_id', $cids)
                ->whereNull('children.deleted_at')
                ->count();
            return [
                'id' => $a->id,
                'name' => $a->name,
                'slug' => $a->slug,
                'billing_status' => $a->billing_status,
                'plan_code' => $a->plan_code,
                'plan_amount_cents' => $a->plan_amount_cents,
                'centre_count' => (int) ($centresPerAgency[$a->id] ?? 0),
                'family_count' => $familyCount,
                'child_count' => $childCount,
                'created_at' => $a->created_at,
                'cancelled_at' => $a->cancelled_at,
            ];
        });

        return response()->json(['agencies' => $result->values()->all()]);
    }

    /**
     * POST /api/v1/platform/agencies
     * Create a brand-new customer agency. The first agency_admin can be
     * invited separately via /admin/users once the agency exists.
     */
    public function createAgency(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'slug' => ['nullable', 'string', 'max:80'],
            'contact_email' => ['nullable', 'email', 'max:180'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'timezone' => ['nullable', 'string', 'max:60'],
            'plan_code' => ['nullable', 'string', 'max:40'],
            'plan_amount_cents' => ['nullable', 'integer', 'min:0'],
        ]);

        $slug = $data['slug'] ?? Str::slug($data['name']);
        if (! $slug) $slug = 'agency_'.uniqid();
        $base = $slug;
        $i = 1;
        while (DB::table('agencies')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        $id = DB::table('agencies')->insertGetId([
            'name' => $data['name'],
            'slug' => $slug,
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'timezone' => $data['timezone'] ?? 'America/Toronto',
            'locale' => 'en-CA',
            'billing_status' => 'trial',
            'plan_code' => $data['plan_code'] ?? null,
            'plan_amount_cents' => $data['plan_amount_cents'] ?? 0,
            'plan_currency' => 'CAD',
            'trial_ends_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'agency' => DB::table('agencies')->where('id', $id)->first(),
            'message' => 'Agency created. Invite the first agency_admin via /admin/users while X-Active-Agency-Id is set to '.$id.'.',
        ], 201);
    }

    /**
     * POST /api/v1/platform/agencies/{agency}/suspend
     */
    public function suspendAgency(Request $request, int $agencyId): JsonResponse
    {
        $exists = DB::table('agencies')->where('id', $agencyId)->whereNull('deleted_at')->exists();
        if (! $exists) return response()->json(['message' => 'Not found'], 404);
        DB::table('agencies')->where('id', $agencyId)->update([
            'billing_status' => 'suspended',
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Agency suspended']);
    }

    /**
     * POST /api/v1/platform/agencies/{agency}/resume
     */
    public function resumeAgency(Request $request, int $agencyId): JsonResponse
    {
        $exists = DB::table('agencies')->where('id', $agencyId)->whereNull('deleted_at')->exists();
        if (! $exists) return response()->json(['message' => 'Not found'], 404);
        DB::table('agencies')->where('id', $agencyId)->update([
            'billing_status' => 'active',
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Agency resumed']);
    }
}
