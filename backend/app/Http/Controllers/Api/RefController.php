<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\RefCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reference IDs (App\Support\RefCode): find a record by its ID, see your own, and set the
 * agency's prefix. Everything is scoped to the ACTIVE agency and to what the caller may open.
 */
final class RefController extends Controller
{
    use ResolvesCentreContext;

    /** GET /refs/lookup?code=ILH-C-0042 — staff only. */
    public function lookup(Request $request): JsonResponse
    {
        $code = (string) $request->query('code', '');
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId, 400, 'Select an agency first.');
        $hit = RefCode::lookup($code, $agencyId);
        $none = response()->json(['found' => false, 'message' => 'No record with that ID in this agency.'], 404);
        if (! $hit) return $none;

        $u = $request->user();
        $roles = DB::table('role_assignments')->where('user_id', $u->id)->where('active', 1)->pluck('role')->all();
        $lead = (bool) array_intersect($roles, ['platform_admin', 'agency_admin', 'centre_director']);

        if ($hit['type'] === 'C') {
            if (! $this->canAccessChildScoped($request, $hit['id'])) return $none;
            $c = DB::table('children')->where('id', $hit['id'])->first(['first_name', 'preferred_name', 'last_name', 'deleted_at']);

            return response()->json($hit + ['found' => true, 'name' => trim(($c->preferred_name ?: $c->first_name) . ' ' . $c->last_name),
                'link' => ($lead ? '#child-detail?id=' : '#child-record?id=') . $hit['id'] . ($c->deleted_at ? '&archived=1' : '')]);
        }
        if ($hit['type'] === 'F') {
            if (! $lead || ! $this->canAccessFamilyScoped($request, $hit['id'])) return $none;
            $f = DB::table('families')->where('id', $hit['id'])->first(['family_name']);
            $child = DB::table('children')->where('family_id', $hit['id'])->whereNull('deleted_at')->orderBy('id')->value('id');

            return response()->json($hit + ['found' => true, 'name' => $f->family_name,
                'link' => $child ? '#child-detail?id=' . $child : '#admin-families', 'family_id' => $hit['id']]);
        }
        // People: admins and directors (a director, for people at their own centres).
        if (! $lead) return $none;
        $p = DB::table('users')->where('id', $hit['id'])->first(['first_name', 'last_name']);
        if (! $p) return $none;
        if (! array_intersect($roles, ['platform_admin', 'agency_admin'])) {
            $mine = $this->visibleCentreIds($request);
            $theirs = DB::table('role_assignments')->where('user_id', $hit['id'])->where('active', 1)->pluck('centre_id')->filter()->all();
            $fam = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->where('g.user_id', $hit['id'])->pluck('f.centre_id')->all();
            if (! array_intersect(array_map('intval', array_merge($theirs, $fam)), array_map('intval', $mine))) return $none;
        }

        return response()->json($hit + ['found' => true, 'name' => trim($p->first_name . ' ' . $p->last_name),
            'link' => '#admin-users?user=' . $hit['id'], 'user_id' => $hit['id']]);
    }

    /** GET /refs/mine — the caller's own IDs at the active agency, and their children's. */
    public function mine(Request $request): JsonResponse
    {
        $u = $request->user();
        $agencyId = (int) $this->resolveAgencyId($request);
        $out = ['agency_prefix' => $agencyId ? RefCode::prefix($agencyId) : null, 'me' => [], 'children' => [], 'families' => []];
        if (! $agencyId) return response()->json($out);
        foreach (['S', 'P'] as $t) {
            if (($t === 'S' && RefCode::isStaff((int) $u->id, $agencyId)) || ($t === 'P' && RefCode::isGuardian((int) $u->id, $agencyId))) {
                $out['me'][$t] = RefCode::ensureUser((int) $u->id, $agencyId, $t);
            }
        }
        $fams = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('g.user_id', $u->id)->where('c.agency_id', $agencyId)->whereNull('f.deleted_at')->get(['f.id', 'f.family_name']);
        foreach ($fams as $f) {
            $out['families'][] = ['id' => (int) $f->id, 'name' => $f->family_name, 'code' => RefCode::ensureFamily((int) $f->id)];
            foreach (DB::table('children')->where('family_id', $f->id)->whereNull('deleted_at')->get(['id', 'first_name', 'preferred_name', 'last_name']) as $c) {
                $out['children'][] = ['id' => (int) $c->id, 'name' => trim(($c->preferred_name ?: $c->first_name) . ' ' . $c->last_name), 'code' => RefCode::ensureChild((int) $c->id)];
            }
        }

        return response()->json($out);
    }

    /** GET /admin/ref-prefix */
    public function prefix(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId, 400, 'Select an agency first.');

        return response()->json($this->prefixInfo($agencyId));
    }

    /** PUT /admin/ref-prefix {prefix} — agency admins. */
    public function setPrefix(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId, 400, 'Select an agency first.');
        $roles = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', 1)
            ->where(fn ($q) => $q->where('role', 'platform_admin')->orWhere(fn ($x) => $x->where('role', 'agency_admin')->where('agency_id', $agencyId)))->exists();
        abort_unless($roles, 403, 'Only an agency admin can change the ID prefix.');
        $p = strtoupper(trim((string) $request->input('prefix', '')));
        if ($err = RefCode::validPrefix($p, $agencyId)) return response()->json(['message' => $err, 'errors' => ['prefix' => [$err]]], 422);
        $before = RefCode::prefix($agencyId);
        if ($p !== $before) {
            RefCode::setPrefix($agencyId, $p);
            try {
                \App\Support\Audit::write(['user_id' => $request->user()->id, 'agency_id' => $agencyId, 'action' => 'agency.ref_prefix',
                    'entity_type' => 'agency', 'entity_id' => $agencyId, 'created_at' => now(),
                    'payload' => json_encode(['before' => $before, 'after' => $p, 'summary' => 'Changed the reference ID prefix from ' . $before . ' to ' . $p . '. IDs printed with ' . $before . ' still find their records.'])]);
            } catch (\Throwable $e) {}
        }

        return response()->json($this->prefixInfo($agencyId));
    }

    private function prefixInfo(int $agencyId): array
    {
        $p = RefCode::prefix($agencyId);

        return ['prefix' => $p, 'examples' => ['child' => $p . '-C-0042', 'family' => $p . '-F-0017', 'staff' => $p . '-S-0008', 'parent' => $p . '-P-0031'],
            'counts' => DB::table('agency_ref_counters')->where('agency_id', $agencyId)->pluck('last_no', 'type')];
    }
}
