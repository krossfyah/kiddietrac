<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Support\CentreScope;
use App\Support\RefCode;
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
 * Minimum term length: 2 chars (1 for a bare reference number). Returns empty results below that.
 *
 * Result groups: centres, families, children, staff, parents, rooms. Each entry has
 * { id, label, sublabel?, hash, ref_code? }. The hash is the destination URL fragment.
 *
 * REFERENCE IDS EVERYWHERE (2026-10-14, Anthony: "make sure the global search also picks up the
 * record ID's as well throughout"). Every child, family, staff and parent result carries its
 * ID (ILH-C-0042) in its sublabel, and an ID typed in full, in part or loosely — "ILH-C-0042",
 * "ilh c 42", "C-0042", "ILH-C-00", "42" — finds its records (RefCode::searchIds), alongside
 * the name matches.
 *
 * SCOPE (fixed the same day): a centre director searched the WHOLE agency here — the comment
 * below admitted it — although directors are centre-scoped everywhere else (CentreScope). And a
 * platform admin with no agency selected silently searched the first agency. Directors now see
 * their own centres; a platform admin must pick an agency.
 */
final class SearchController extends Controller
{
    private const LIMITS = ['centres' => 5, 'families' => 6, 'children' => 8, 'staff' => 6, 'parents' => 6, 'rooms' => 5];

    public function query(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $isNumber = (bool) preg_match('/^\d{1,7}$/', $q);

        if (mb_strlen($q) < 2 && ! $isNumber) {
            return response()->json(['q' => $q, 'results' => $this->emptyGroups()]);
        }

        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) {
            return response()->json(['message' => 'Select an agency first.'], 403);
        }

        // The caller's centres: null = the whole agency (admins), else a director's own.
        $scope = CentreScope::ids($request, $agencyId);
        $agencyCentres = DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')->pluck('id')->map(fn ($x) => (int) $x)->all();
        $centreIds = $scope === null ? $agencyCentres : array_values(array_intersect($agencyCentres, array_map('intval', $scope)));
        $wholeAgency = $scope === null;

        // Reference IDs in the query (full, partial or loose). [] when it is not an ID.
        $refs = RefCode::searchIds($q, $agencyId) ?? [];
        $exact = RefCode::lookup($q, $agencyId);

        $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q).'%';
        $nameSearch = ! $isNumber;

        $centres = ! $nameSearch || ! $centreIds ? collect() : DB::table('centres')
            ->whereIn('id', $centreIds)
            ->where(fn ($w) => $w->where('name', 'LIKE', $term)->orWhere('city', 'LIKE', $term))
            ->limit(self::LIMITS['centres'])
            ->get(['id', 'name', 'city']);

        // Families: by name, or by their ID.
        $famIds = $refs['F'] ?? [];
        $families = ! $centreIds ? collect() : DB::table('families')
            ->whereIn('centre_id', $centreIds)
            ->where(function ($w) use ($nameSearch, $term, $famIds) {
                if ($nameSearch) $w->where(fn ($x) => $x->whereNull('deleted_at')->where('family_name', 'LIKE', $term));
                if ($famIds) $w->orWhereIn('id', $famIds);
                if (! $nameSearch && ! $famIds) $w->whereRaw('1=0');
            })
            ->orderByRaw($famIds ? 'FIELD(id,' . implode(',', array_map('intval', $famIds)) . ') = 0, FIELD(id,' . implode(',', array_map('intval', $famIds)) . ')' : '1')
            ->limit(self::LIMITS['families'])
            ->get(['id', 'family_name', 'centre_id', 'deleted_at']);

        // Children: by name, or by their ID (an archived child is still found by its ID —
        // the ID is printed on documents kept for years).
        $kidIds = $refs['C'] ?? [];
        $children = ! $centreIds ? collect() : DB::table('children')
            ->join('families', 'families.id', '=', 'children.family_id')
            ->whereIn('families.centre_id', $centreIds)
            ->where(function ($w) use ($nameSearch, $term, $kidIds) {
                if ($nameSearch) {
                    $w->where(fn ($x) => $x->whereNull('children.deleted_at')->where(fn ($y) => $y
                        ->where('children.first_name', 'LIKE', $term)
                        ->orWhere('children.last_name', 'LIKE', $term)
                        ->orWhere('children.preferred_name', 'LIKE', $term)));
                }
                if ($kidIds) $w->orWhereIn('children.id', $kidIds);
                if (! $nameSearch && ! $kidIds) $w->whereRaw('1=0');
            })
            ->orderByRaw($kidIds ? 'FIELD(children.id,' . implode(',', array_map('intval', $kidIds)) . ') = 0, FIELD(children.id,' . implode(',', array_map('intval', $kidIds)) . ')' : '1')
            ->limit(self::LIMITS['children'])
            ->get([
                'children.id', 'children.first_name', 'children.last_name', 'children.deleted_at',
                'children.preferred_name', 'families.family_name',
            ]);

        // Staff: an active non-guardian role at the agency (admins) or at the caller's centres.
        $staffUserIds = DB::table('role_assignments')
            ->where('active', true)
            ->where('role', '!=', 'guardian')
            ->where(fn ($w) => $w
                ->when($wholeAgency, fn ($x) => $x->where('agency_id', $agencyId))
                ->orWhereIn('centre_id', $centreIds ?: [0]))
            ->pluck('user_id')->map(fn ($x) => (int) $x)->unique()->all();
        $staff = $this->people($staffUserIds, $refs['S'] ?? [], $nameSearch, $term, self::LIMITS['staff']);

        // Parents: guardians of a family at the caller's centres.
        $parentUserIds = ! $centreIds ? [] : DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
            ->whereIn('f.centre_id', $centreIds)->pluck('g.user_id')->map(fn ($x) => (int) $x)->unique()->all();
        $parents = $this->people($parentUserIds, $refs['P'] ?? [], $nameSearch, $term, self::LIMITS['parents']);

        // rooms table has no deleted_at column (verified at runtime).
        $rooms = ! $nameSearch || ! $centreIds ? collect() : DB::table('rooms')
            ->whereIn('centre_id', $centreIds)
            ->where('name', 'LIKE', $term)
            ->limit(self::LIMITS['rooms'])
            ->get(['id', 'name', 'centre_id']);

        // Their IDs, in one query per kind.
        $kidCodes = RefCode::children($children->pluck('id')->all());
        $famCodes = RefCode::families($families->pluck('id')->all());
        $staffCodes = RefCode::users($staff->pluck('id')->all(), $agencyId, 'S');
        $parentCodes = RefCode::users($parents->pluck('id')->all(), $agencyId, 'P');
        $firstChild = $families->isEmpty() ? collect() : DB::table('children')->whereIn('family_id', $families->pluck('id'))
            ->whereNull('deleted_at')->orderBy('id')->get(['id', 'family_id'])->groupBy('family_id')->map(fn ($g) => (int) $g->first()->id);
        $sub = fn (?string $code, ?string $rest) => trim(($code ? $code . ' · ' : '') . ($rest ?? ''), ' ·');

        return response()->json([
            'q' => $q,
            'ref' => $exact ? ['type' => $exact['type'], 'id' => $exact['id'], 'code' => $exact['code']] : null,
            'results' => [
                'centres' => $centres->map(fn ($c) => [
                    'id' => $c->id,
                    'label' => $c->name,
                    'sublabel' => $c->city,
                    'hash' => '#admin-centres',
                ])->values()->all(),
                'families' => $families->map(fn ($f) => [
                    'id' => $f->id,
                    'label' => $f->family_name . ($f->deleted_at ? ' (archived)' : ''),
                    'sublabel' => $sub($famCodes[$f->id] ?? null, 'Family'),
                    'ref_code' => $famCodes[$f->id] ?? null,
                    // Directors have no Families screen: their family hit opens the first child's record.
                    'hash' => $wholeAgency ? '#admin-families?family=' . $f->id . ($f->deleted_at ? '&archived=1' : '')
                        : (isset($firstChild[$f->id]) ? '#child-detail?id=' . $firstChild[$f->id] : '#dashboard'),
                ])->values()->all(),
                'children' => $children->map(fn ($c) => [
                    'id' => $c->id,
                    'label' => trim(($c->preferred_name ?: $c->first_name).' '.$c->last_name) . ($c->deleted_at ? ' (archived)' : ''),
                    'sublabel' => $sub($kidCodes[$c->id] ?? null, ($c->family_name ?: '—').' family'),
                    'ref_code' => $kidCodes[$c->id] ?? null,
                    'hash' => '#child-detail?id='.$c->id . ($c->deleted_at ? '&archived=1' : ''),
                ])->values()->all(),
                'staff' => $staff->map(fn ($u) => [
                    'id' => $u->id,
                    'label' => trim(($u->preferred_name ?: $u->first_name).' '.$u->last_name),
                    'sublabel' => $sub($staffCodes[$u->id] ?? null, $u->email),
                    'ref_code' => $staffCodes[$u->id] ?? null,
                    'hash' => '#admin-users?user=' . $u->id,
                ])->values()->all(),
                'parents' => $parents->map(fn ($u) => [
                    'id' => $u->id,
                    'label' => trim(($u->preferred_name ?: $u->first_name).' '.$u->last_name),
                    'sublabel' => $sub($parentCodes[$u->id] ?? null, $u->email),
                    'ref_code' => $parentCodes[$u->id] ?? null,
                    'hash' => '#admin-users?user=' . $u->id,
                ])->values()->all(),
                'rooms' => $rooms->map(fn ($r) => [
                    'id' => $r->id,
                    'label' => $r->name,
                    'sublabel' => 'Room',
                    'hash' => '#admin-centres',
                ])->values()->all(),
            ],
        ]);
    }

    /** People from a candidate set, by name/email or by ID. */
    private function people(array $candidates, array $byRef, bool $nameSearch, string $term, int $limit)
    {
        $byRef = array_values(array_intersect(array_map('intval', $byRef), $candidates));
        if (! $candidates || (! $nameSearch && ! $byRef)) return collect();

        return DB::table('users')
            ->whereIn('id', $candidates)
            ->whereNull('deleted_at')
            ->where(function ($w) use ($nameSearch, $term, $byRef) {
                if ($nameSearch) {
                    $w->where(fn ($x) => $x
                        ->where('first_name', 'LIKE', $term)
                        ->orWhere('last_name', 'LIKE', $term)
                        ->orWhere('preferred_name', 'LIKE', $term)
                        ->orWhere('email', 'LIKE', $term));
                }
                if ($byRef) $w->orWhereIn('id', $byRef);
            })
            ->orderByRaw($byRef ? 'FIELD(id,' . implode(',', $byRef) . ') = 0, FIELD(id,' . implode(',', $byRef) . ')' : '1')
            ->limit($limit)
            ->get(['id', 'first_name', 'last_name', 'preferred_name', 'email']);
    }

    private function emptyGroups(): array
    {
        return [
            'centres' => [], 'families' => [], 'children' => [],
            'staff' => [], 'parents' => [], 'rooms' => [],
        ];
    }

    private function resolveAgencyId(Request $request): ?int
    {
        // v22p20: honor X-Active-Agency-Id header for multi-agency admins.
        $isPlatformAdmin = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'platform_admin')
            ->where('active', true)
            ->exists();
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($isPlatformAdmin) {
            /* A platform admin searches the agency they have SELECTED, never a default:
               the old fallback to the first agency returned iLearn's people to a super-admin
               with no agency chosen (the same leak fixed elsewhere in v22p98). */
            return ($activeId && DB::table('agencies')->where('id', $activeId)->whereNull('deleted_at')->exists())
                ? $activeId : null;
        }
        if ($activeId && DB::table('role_assignments')
                ->where('user_id', $request->user()->id)
                ->whereIn('role', ['agency_admin', 'centre_director'])
                ->where('active', true)
                ->where(fn ($w) => $w->where('agency_id', $activeId)
                    ->orWhereIn('centre_id', DB::table('centres')->where('agency_id', $activeId)->pluck('id')))
                ->exists()) {
            return $activeId;
        }
        $byAgencyAdmin = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'agency_admin')
            ->where('active', true)
            ->value('agency_id');
        if ($byAgencyAdmin) return (int) $byAgencyAdmin;

        // Director: the agency of their centre. What they SEE is narrowed to their own
        // centres by CentreScope in query().
        $directorCentreId = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'centre_director')
            ->where('active', true)
            ->value('centre_id');
        if ($directorCentreId) {
            return (int) DB::table('centres')->where('id', $directorCentreId)->value('agency_id');
        }
        return null;
    }
}
