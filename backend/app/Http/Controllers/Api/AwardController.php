<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Models\ChildAward;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Child awards — educators/directors issue them; parents view them.
 *
 * Endpoints:
 *   POST   /provider/awards        create (educator/director)
 *   GET    /provider/awards        list (agency's children)
 *   DELETE /provider/awards/{id}   remove
 *   GET    /parent/awards          the parent's own children's awards
 */
class AwardController extends Controller
{
    use ResolvesCentreContext;

    private const WITH = ['child:id,first_name,last_name,photo_url', 'awardedBy:id,first_name,last_name'];

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id'   => 'required|integer|exists:children,id',
            'title'      => 'required|string|max:120',
            'badge'      => 'nullable|string|max:16',
            'period'     => 'nullable|in:daily,weekly,monthly',
            'note'       => 'nullable|string|max:1000',
            'awarded_on' => 'nullable|date',
        ]);
        abort_unless($this->canAccessChildId($request->user(), (int) $data['child_id']), 403);

        // Centre = the child's family centre.
        $centreId = (int) DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->where('c.id', (int) $data['child_id'])
            ->value('f.centre_id');

        $award = ChildAward::create(array_merge($data, [
            'centre_id'     => $centreId ?: null,
            'awarded_by_id' => $request->user()->id,
            'awarded_on'    => $data['awarded_on'] ?? now()->toDateString(),
        ]));
        $award->load(self::WITH);

        // Notify the agency's admins + directors that an award was issued so it
        // shows in their notification feed. Never let this break the award.
        try {
            $agencyId = $centreId ? (int) DB::table('centres')->where('id', $centreId)->value('agency_id') : null;
            if ($agencyId) {
                $childName = trim((string) ($award->child->first_name ?? '') . ' ' . (string) ($award->child->last_name ?? ''));
                $byName = trim((string) ($award->awardedBy->first_name ?? '') . ' ' . (string) ($award->awardedBy->last_name ?? ''));
                $recipients = DB::table('role_assignments')
                    ->whereIn('role', ['agency_admin', 'centre_director'])->where('active', true)
                    ->where(function ($q) use ($agencyId) {
                        $q->where('agency_id', $agencyId)
                          ->orWhereIn('centre_id', function ($qq) use ($agencyId) {
                              $qq->select('id')->from('centres')->where('agency_id', $agencyId);
                          });
                    })
                    ->where('user_id', '!=', $request->user()->id)
                    ->distinct()->pluck('user_id')->all();

                $title = '🏆 Award issued' . ($childName ? ' · ' . $childName : '');
                $body = ($byName ? $byName . ' gave ' : 'Awarded ') . ($childName ?: 'a child')
                    . ' the “' . $data['title'] . '” award.';
                $now = now();
                foreach ($recipients as $uid) {
                    DB::table('notifications')->insert([
                        'user_id' => $uid,
                        'type' => 'award',
                        'title' => $title,
                        'body' => mb_substr($body, 0, 500),
                        'data' => json_encode(['award_id' => $award->id, 'child_id' => (int) $data['child_id']]),
                        'created_at' => $now,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // notification is best-effort
        }

        return response()->json(['data' => $award], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $agencyId  = $this->resolveAgencyId($request);
        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        $childIds  = DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->whereIn('f.centre_id', $centreIds ?: [0])
            ->pluck('c.id')->all();

        $q = ChildAward::with(self::WITH)
            ->whereIn('child_id', $childIds ?: [0])
            ->orderByDesc('awarded_on')->orderByDesc('id');

        if ($cid = (int) $request->query('child_id')) {
            $q->where('child_id', $cid);
        }

        $awards = $q->limit(200)->get();
        $this->stampAgency($awards, $agencyId ? (int) $agencyId : null);

        return response()->json(['data' => $awards]);
    }

    /**
     * Attach the issuing agency's name + owner to each award so the printable
     * certificate can read "presented by <Agency>, on behalf of <Owner>". Owner =
     * earliest agency_admin, else earliest centre_director.
     */
    private function stampAgency($awards, ?int $agencyId): void
    {
        $name = '';
        $owner = '';
        if ($agencyId) {
            $name = (string) DB::table('agencies')->where('id', $agencyId)->value('name');
            $ownerRow = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->whereIn('ra.role', ['agency_admin', 'centre_director'])->where('ra.active', true)
                ->where(function ($q) use ($agencyId) {
                    $q->where('ra.agency_id', $agencyId)
                      ->orWhereIn('ra.centre_id', function ($qq) use ($agencyId) {
                          $qq->select('id')->from('centres')->where('agency_id', $agencyId);
                      });
                })
                ->orderByRaw("CASE WHEN ra.role = 'agency_admin' THEN 0 ELSE 1 END")
                ->orderBy('ra.created_at')
                ->first(['u.first_name', 'u.last_name']);
            if ($ownerRow) {
                $owner = trim(($ownerRow->first_name ?? '') . ' ' . ($ownerRow->last_name ?? ''));
            }
        }
        $awards->each(function ($a) use ($name, $owner) {
            $a->agency_name = $name;
            $a->owner_name = $owner;
        });
    }

    /**
     * The children the caller may award — every child in the centres they can
     * access (educators → their assigned centres; directors/admins → the whole
     * agency). Reuses authorizeCentreAccess so it matches canAccessChildId, which
     * still guards the actual award on store().
     */
    public function roster(Request $request): JsonResponse
    {
        $user = $request->user();
        $agencyId = $this->resolveAgencyId($request);

        $centreIds = DB::table('centres')
            ->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->pluck('id')->all();

        $allowed = array_values(array_filter(
            $centreIds,
            fn ($cid) => $this->authorizeCentreAccess($user, (int) $cid)
        ));

        $children = $allowed
            ? DB::table('children as c')
                ->join('families as f', 'f.id', '=', 'c.family_id')
                ->whereIn('f.centre_id', $allowed)
                ->whereNull('c.deleted_at')
                ->orderBy('c.first_name')->orderBy('c.last_name')
                ->get(['c.id', 'c.first_name', 'c.last_name', 'c.photo_url', 'f.centre_id'])
            : collect();

        return response()->json(['data' => $children]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $award = ChildAward::findOrFail($id);
        abort_unless($this->canAccessChildId($request->user(), (int) $award->child_id), 403);
        $award->delete();

        return response()->json(['ok' => true]);
    }

    public function forParent(Request $request): JsonResponse
    {
        $userId    = $request->user()->id;
        $familyIds = DB::table('guardians')->where('user_id', $userId)->pluck('family_id')->all();
        $childIds  = DB::table('children')
            ->whereIn('family_id', $familyIds ?: [0])
            ->whereNull('deleted_at')
            ->pluck('id')->all();

        $awards = ChildAward::with(self::WITH)
            ->whereIn('child_id', $childIds ?: [0])
            ->orderByDesc('awarded_on')->orderByDesc('id')
            ->limit(200)->get();

        $agencyId = (int) DB::table('guardians as g')
            ->join('families as f', 'f.id', '=', 'g.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('g.user_id', $userId)->value('c.agency_id');
        $this->stampAgency($awards, $agencyId ?: null);

        return response()->json(['data' => $awards]);
    }
}
