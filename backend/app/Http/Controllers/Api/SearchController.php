<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p4.6 — Global portal search.
 *
 * Single endpoint, scoped to the calling user's agency. Returns a typed,
 * grouped result set so the frontend can route each click to the right hash.
 *
 * GET /api/v1/admin/search?q=<term>
 *
 * Minimum term length: 2 chars. Returns empty results below that.
 *
 * Result groups: centres, families, children, staff, rooms. Each entry has
 * { id, label, sublabel?, hash }. The hash is the destination URL fragment.
 */
final class SearchController extends Controller
{
    public function query(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['q' => $q, 'results' => $this->emptyGroups()]);
        }

        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) {
            return response()->json(['message' => 'No agency context'], 403);
        }

        $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q).'%';
        $centreIds = DB::table('centres')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->all();

        $centres = DB::table('centres')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->where(fn ($w) => $w
                ->where('name', 'LIKE', $term)
                ->orWhere('city', 'LIKE', $term))
            ->limit(5)
            ->get(['id', 'name', 'city']);

        $families = empty($centreIds) ? collect() : DB::table('families')
            ->whereIn('centre_id', $centreIds)
            ->whereNull('deleted_at')
            ->where('family_name', 'LIKE', $term)
            ->limit(5)
            ->get(['id', 'family_name', 'centre_id']);

        $children = empty($centreIds) ? collect() : DB::table('children')
            ->join('families', 'families.id', '=', 'children.family_id')
            ->whereIn('families.centre_id', $centreIds)
            ->whereNull('children.deleted_at')
            ->where(fn ($w) => $w
                ->where('children.first_name', 'LIKE', $term)
                ->orWhere('children.last_name', 'LIKE', $term)
                ->orWhere('children.preferred_name', 'LIKE', $term))
            ->limit(8)
            ->get([
                'children.id', 'children.first_name', 'children.last_name',
                'children.preferred_name', 'families.family_name',
            ]);

        // Staff: users with an active role_assignment pinned to this agency
        // (agency_admin) OR to one of its centres (centre_director/educator).
        $staffUserIds = DB::table('role_assignments')
            ->where('active', true)
            ->where(fn ($w) => $w
                ->where('agency_id', $agencyId)
                ->orWhereIn('centre_id', $centreIds))
            ->pluck('user_id')
            ->unique()
            ->all();

        $staff = empty($staffUserIds) ? collect() : DB::table('users')
            ->whereIn('id', $staffUserIds)
            ->whereNull('deleted_at')
            ->where(fn ($w) => $w
                ->where('first_name', 'LIKE', $term)
                ->orWhere('last_name', 'LIKE', $term)
                ->orWhere('preferred_name', 'LIKE', $term)
                ->orWhere('email', 'LIKE', $term))
            ->limit(6)
            ->get(['id', 'first_name', 'last_name', 'preferred_name', 'email']);

        // rooms table has no deleted_at column (verified at runtime).
        $rooms = empty($centreIds) ? collect() : DB::table('rooms')
            ->whereIn('centre_id', $centreIds)
            ->where('name', 'LIKE', $term)
            ->limit(5)
            ->get(['id', 'name', 'centre_id']);

        return response()->json([
            'q' => $q,
            'results' => [
                'centres' => $centres->map(fn ($c) => [
                    'id' => $c->id,
                    'label' => $c->name,
                    'sublabel' => $c->city,
                    'hash' => '#admin-centres',
                ])->all(),
                'families' => $families->map(fn ($f) => [
                    'id' => $f->id,
                    'label' => $f->family_name,
                    'sublabel' => 'Family',
                    'hash' => '#admin-families',
                ])->all(),
                'children' => $children->map(fn ($c) => [
                    'id' => $c->id,
                    'label' => trim(($c->preferred_name ?: $c->first_name).' '.$c->last_name),
                    'sublabel' => ($c->family_name ?: '—').' family',
                    'hash' => '#child-detail?id='.$c->id,
                ])->all(),
                'staff' => $staff->map(fn ($u) => [
                    'id' => $u->id,
                    'label' => trim(($u->preferred_name ?: $u->first_name).' '.$u->last_name),
                    'sublabel' => $u->email,
                    'hash' => '#admin-users',
                ])->all(),
                'rooms' => $rooms->map(fn ($r) => [
                    'id' => $r->id,
                    'label' => $r->name,
                    'sublabel' => 'Room',
                    'hash' => '#admin-centres',
                ])->all(),
            ],
        ]);
    }

    private function emptyGroups(): array
    {
        return [
            'centres' => [], 'families' => [], 'children' => [],
            'staff' => [], 'rooms' => [],
        ];
    }

    private function resolveAgencyId(Request $request): ?int
    {
        // v22p20: honor X-Active-Agency-Id header for multi-agency admins.
        // v22p21: platform_admin trumps tenant scope — they can target ANY agency.
        $isPlatformAdmin = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'platform_admin')
            ->where('active', true)
            ->exists();
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($isPlatformAdmin) {
            if ($activeId && DB::table('agencies')->where('id', $activeId)->whereNull('deleted_at')->exists()) {
                return $activeId;
            }
            // platform_admin without header — default to the first agency.
            $first = DB::table('agencies')->whereNull('deleted_at')->orderBy('id')->value('id');
            if ($first) return (int) $first;
        }
        if ($activeId && DB::table('role_assignments')
                ->where('user_id', $request->user()->id)
                ->where('role', 'agency_admin')
                ->where('agency_id', $activeId)
                ->where('active', true)
                ->exists()) {
            return $activeId;
        }
        return DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'agency_admin')
            ->where('active', true)
            ->value('agency_id');
    }
}
