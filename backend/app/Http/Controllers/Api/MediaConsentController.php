<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\MediaConsent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PHOTO PERMISSIONS (2026-11-06) - the screens over App\Support\MediaConsent.
 *
 * Parents:  GET/POST /me/photo-consent           their own answer for each of their children (kt-photo-consent.js asks
 *                                                until every child has one).
 * Staff:    GET  /admin/photo-consent              every child they can see, with the effective permission
 *                                                (educators read it - they are the ones holding the camera);
 *           POST /admin/photo-consent/{child}      an admin or director records a parent's SIGNED PAPER answer
 *           POST /admin/photo-consent/{child}/show-photos
 *                                                show photos hidden for no consent again, once the family allows it.
 * Scoped to the active agency and, for a director or educator, their own centres. Fails closed.
 */
final class MediaConsentController extends Controller
{
    /* ── parents ─────────────────────────────────────────────────────────────────────────────────────────────── */

    public function mine(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $fams = DB::table('guardians')->where('user_id', $uid)->pluck('family_id')->filter()->all();
        $kids = DB::table('children')->whereIn('family_id', $fams ?: [0])->whereNull('deleted_at')->whereNull('withdrawn_at')
            ->orderBy('first_name')->get(['id', 'first_name']);
        $mine = DB::table('child_media_consents')->where('user_id', $uid)->whereIn('child_id', $kids->pluck('id')->all() ?: [0])->get()->keyBy('child_id');
        $eff = MediaConsent::forChildren($kids->pluck('id')->all());
        // Only the primary parent chooses (MediaConsent::decidersFor). Everyone else sees what applies, and who chose.
        $deciders = MediaConsent::decidersFor($kids->pluck('id')->all());
        $names = DB::table('users')->whereIn('id', collect($deciders)->flatten()->all() ?: [0])->pluck('first_name', 'id');
        $applied = DB::table('child_media_consents')->whereIn('child_id', $kids->pluck('id')->all() ?: [0])->get(['child_id', 'user_id', 'level', 'marketing'])->groupBy('child_id');

        return response()->json([
            'labels' => MediaConsent::LABELS,
            'children' => $kids->map(function ($k) use ($mine, $eff, $deciders, $names, $uid) {
                $ds = $deciders[(int) $k->id] ?? [];
                $can = in_array($uid, $ds, true);
                $e = $eff[(int) $k->id] ?? ['level' => MediaConsent::FAMILY, 'answered' => 0, 'marketing' => false];
                return [
                    'id' => (int) $k->id, 'first_name' => $k->first_name,
                    // A parent who does not choose is shown what applies, not their own old answer.
                    'level' => $can ? ($mine[$k->id]->level ?? null) : ($e['answered'] ? $e['level'] : null),
                    'marketing' => $can ? (bool) ($mine[$k->id]->marketing ?? false) : (bool) $e['marketing'],
                    'effective' => $e['level'],
                    'can_answer' => $can,
                    'chosen_by' => $can ? null : collect($ds)->map(fn ($id) => $names[$id] ?? null)->filter()->values()->all(),
                    // A second primary parent's more careful answer is what applies; say so without saying who.
                    'stricter_elsewhere' => $can && isset($mine[$k->id]) && MediaConsent::RANK[$e['level']] < MediaConsent::RANK[$mine[$k->id]->level],
                ];
            })->values(),
            'pending' => count(MediaConsent::pendingFor($uid)),
            // A super admin viewing as this parent sees the answers but is never asked, and cannot answer.
            'viewing_as' => $this->viewingAs($request),
        ]);
    }

    public function saveMine(Request $request): JsonResponse
    {
        abort_if($this->viewingAs($request), 403, 'Only the parent can give photo permission, not someone viewing as them.');
        $uid = (int) $request->user()->id;
        $data = $request->validate([
            'answers' => ['required', 'array', 'min:1', 'max:20'],
            'answers.*.child_id' => ['required', 'integer'],
            'answers.*.level' => ['required', 'in:none,family,group'],
            'answers.*.marketing' => ['sometimes', 'boolean'],
            // The sign-up wizard asks too (screen-onboarding.js, 2026-11-06); the answer says where it was given.
            'source' => ['sometimes', 'in:parent,onboarding'],
        ]);
        $source = $data['source'] ?? 'parent';
        $fams = DB::table('guardians')->where('user_id', $uid)->pluck('family_id')->filter()->all();
        $mineIds = DB::table('children')->whereIn('family_id', $fams ?: [0])->pluck('id')->map(fn ($v) => (int) $v)->all();
        $deciders = MediaConsent::decidersFor(array_map(fn ($a) => (int) $a['child_id'], $data['answers']));
        foreach ($data['answers'] as $a) {
            $cid = (int) $a['child_id'];
            abort_unless(in_array($cid, $mineIds, true), 403, 'You can only answer for your own children.');
            abort_unless(in_array($uid, $deciders[$cid] ?? [], true), 403, 'Only the primary parent can choose photo permission for this child.');
            $before = MediaConsent::levelOf($cid);
            $after = MediaConsent::record($cid, $uid, $a['level'], (bool) ($a['marketing'] ?? false), $source, $uid);
            $this->audit($request, $cid, 'photo_consent.parent', 'Gave photo permission for a child: ' . (MediaConsent::LABELS[$a['level']] ?? $a['level'])
                . (! empty($a['marketing']) && $a['level'] !== MediaConsent::NONE ? ', and yes to marketing' : '') . ($before !== $after ? ' (now ' . $after . ')' : ''));
        }

        return $this->mine($request);
    }

    /* ── staff ──────────────────────────────────────────────────────────────────────────────────────────────── */

    public function index(Request $request): JsonResponse
    {
        [$agencyId, $centreIds, $canEdit] = $this->staffScope($request);
        $kids = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')->join('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->where('ce.agency_id', $agencyId)->whereNull('c.deleted_at')->whereNull('c.withdrawn_at')
            ->when($centreIds !== null, fn ($q) => $q->whereIn('f.centre_id', $centreIds ?: [0]))
            // One family's children (the family record) or one parent's (the user record), 2026-11-06.
            ->when($request->filled('family_id'), fn ($q) => $q->where('c.family_id', (int) $request->query('family_id')))
            ->when($request->filled('user_id'), fn ($q) => $q->whereIn('c.family_id',
                DB::table('guardians')->where('user_id', (int) $request->query('user_id'))->pluck('family_id')->filter()->all() ?: [0]))
            ->orderBy('c.first_name')->orderBy('c.last_name')
            ->get(['c.id', 'c.first_name', 'c.last_name', 'c.family_id', 'ce.name as centre']);
        $ids = $kids->pluck('id')->map(fn ($v) => (int) $v)->all();
        $eff = MediaConsent::forChildren($ids);
        $deciders = MediaConsent::decidersFor($ids);
        // The tag picker only needs each child's level (kt-child-tagger.js).
        if ($request->boolean('levels_only')) {
            return response()->json(['levels' => (object) array_map(fn ($e) => $e['level'], $eff)]);
        }
        $answers = DB::table('child_media_consents as m')->join('users as u', 'u.id', '=', 'm.user_id')->whereIn('m.child_id', $ids ?: [0])
            ->get(['m.child_id', 'm.user_id', 'm.level', 'm.marketing', 'm.source', 'm.updated_at', 'u.first_name'])->groupBy('child_id');
        $guardians = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
            ->whereIn('g.family_id', $kids->pluck('family_id')->unique()->all() ?: [0])->get(['g.family_id', 'u.id', 'u.first_name', 'g.is_primary'])->groupBy('family_id');
        $hidden = DB::table('photos as p')->join('centres as ce', 'ce.id', '=', 'p.centre_id')->where('ce.agency_id', $agencyId)
            ->whereNotNull('p.hidden_at')->where('p.hidden_reason', 'no_consent')->get(['p.id', 'p.child_ids']);
        $hiddenBy = [];
        $mineSet = array_flip($ids);
        foreach ($hidden as $p) foreach (MediaConsent::tagged($p) as $c) if (isset($mineSet[$c])) $hiddenBy[$c] = ($hiddenBy[$c] ?? 0) + 1;

        $rows = $kids->map(function ($k) use ($eff, $answers, $guardians, $hiddenBy, $deciders) {
            $ds = $deciders[(int) $k->id] ?? [];
            $e = $eff[(int) $k->id] ?? ['level' => MediaConsent::FAMILY, 'answered' => 0, 'parents' => 0, 'marketing' => false];
            return [
                'id' => (int) $k->id, 'name' => trim($k->first_name . ' ' . $k->last_name), 'centre' => $k->centre,
                'level' => $e['level'], 'answered' => $e['answered'], 'parents' => $e['parents'], 'marketing' => $e['marketing'],
                'hidden_photos' => $hiddenBy[(int) $k->id] ?? 0,
                'answers' => ($answers[$k->id] ?? collect())->map(fn ($a) => ['user_id' => (int) $a->user_id, 'first_name' => $a->first_name,
                    'level' => $a->level, 'marketing' => (bool) $a->marketing, 'source' => $a->source, 'on' => substr((string) $a->updated_at, 0, 10),
                    'counts' => in_array((int) $a->user_id, $ds, true)])->values(),
                // decides: the primary parent, the only one whose answer counts (and the only one a paper form is recorded for).
                'guardians' => ($guardians[$k->family_id] ?? collect())->map(fn ($g) => ['user_id' => (int) $g->id, 'first_name' => $g->first_name,
                    'primary' => (bool) $g->is_primary, 'decides' => in_array((int) $g->id, $ds, true)])->values(),
            ];
        })->values();

        return response()->json([
            'labels' => MediaConsent::LABELS,
            'can_edit' => $canEdit,
            'children' => $rows,
            'counts' => [
                'none' => $rows->where('level', MediaConsent::NONE)->count(),
                'family' => $rows->where('level', MediaConsent::FAMILY)->count(),
                'group' => $rows->where('level', MediaConsent::GROUP)->count(),
                'unanswered' => $rows->where('answered', 0)->count(),
            ],
        ]);
    }

    /** An admin or director records a parent's signed paper answer. */
    public function record(Request $request, int $child): JsonResponse
    {
        [$agencyId, $centreIds, $canEdit] = $this->staffScope($request);
        abort_unless($canEdit, 403, 'Only admins and directors can record photo permission.');
        $this->assertChild($agencyId, $centreIds, $child);
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'level' => ['required', 'in:none,family,group'],
            'marketing' => ['sometimes', 'boolean'],
            'note' => ['required', 'string', 'max:300'],
        ]);
        $fam = (int) DB::table('children')->where('id', $child)->value('family_id');
        abort_unless(DB::table('guardians')->where('family_id', $fam)->where('user_id', (int) $data['user_id'])->exists(), 422, 'Choose one of this child\'s parents.');
        abort_unless(MediaConsent::decides($child, (int) $data['user_id']), 422, 'Only the primary parent\'s answer counts. Record the form the primary parent signed.');
        $after = MediaConsent::record($child, (int) $data['user_id'], $data['level'], (bool) ($data['marketing'] ?? false), 'paper',
            (int) $request->user()->id, $data['note']);
        $this->audit($request, $child, 'photo_consent.paper', 'Recorded a signed photo permission form: ' . MediaConsent::LABELS[$data['level']] . ' (now ' . $after . ')');

        return $this->index($request);
    }

    /** Show photos hidden for no consent again - only once nobody on them is at "do not photograph". */
    public function showPhotos(Request $request, int $child): JsonResponse
    {
        [$agencyId, $centreIds, $canEdit] = $this->staffScope($request);
        abort_unless($canEdit, 403, 'Only admins and directors can do this.');
        $this->assertChild($agencyId, $centreIds, $child);
        abort_if(MediaConsent::levelOf($child) === MediaConsent::NONE, 422, 'This child\'s family has asked that they not be photographed.');
        $photos = DB::table('photos')->whereNotNull('hidden_at')->where('hidden_reason', 'no_consent')->whereJsonContains('child_ids', $child)->get(['id', 'child_ids', 'hidden_at']);
        $shown = 0;
        foreach ($photos as $p) {
            if (MediaConsent::untaggable(MediaConsent::tagged($p))) continue;   // someone else on it still says no
            DB::table('photos')->where('id', $p->id)->update(['hidden_at' => null, 'hidden_reason' => null, 'hidden_by_id' => null]);
            DB::table('daily_events')->where('photo_id', $p->id)->where('deleted_at', '>=', $p->hidden_at)->update(['deleted_at' => null]);
            $shown++;
        }
        $this->audit($request, $child, 'photo_consent.shown', 'Showed ' . $shown . ' photo(s) again that had been hidden for no photo permission');

        return response()->json(['shown' => $shown] + json_decode($this->index($request)->getContent(), true));
    }

    /* ── helpers ────────────────────────────────────────────────────────────────────────────────────────────── */

    private function viewingAs(Request $request): bool
    {
        $pat = method_exists($request->user(), 'currentAccessToken') ? $request->user()->currentAccessToken() : null;

        return $pat && isset($pat->name) && str_starts_with((string) $pat->name, 'impersonation:');
    }

    /** [agencyId, centre ids (null = every centre), can edit]. Fails closed. */
    private function staffScope(Request $request): array
    {
        $uid = (int) $request->user()->id;
        $activeId = (int) $request->header('X-Active-Agency-Id');
        $platform = DB::table('role_assignments')->where('user_id', $uid)->where('role', 'platform_admin')->where('active', 1)->exists();
        if (! $activeId) {
            abort_if($platform, 400, 'Select an agency first.');
            $activeId = (int) DB::table('role_assignments')->where('user_id', $uid)->whereIn('role', ['agency_admin', 'centre_director', 'educator'])
                ->where('active', 1)->orderByRaw("FIELD(role,'agency_admin','centre_director','educator')")->value('agency_id');
        }
        abort_unless($activeId, 403);
        if ($platform || DB::table('role_assignments')->where('user_id', $uid)->where('agency_id', $activeId)->where('role', 'agency_admin')->where('active', 1)->exists()) {
            return [$activeId, null, true];
        }
        $roles = DB::table('role_assignments')->where('user_id', $uid)->where('agency_id', $activeId)->where('active', 1)
            ->whereIn('role', ['centre_director', 'educator'])->get(['role', 'centre_id']);
        abort_unless($roles->isNotEmpty(), 403, 'Only staff can see photo permissions.');
        $centres = \App\Support\Visibility::centreIds($activeId, $request->user());

        return [$activeId, array_values(array_map('intval', (array) $centres)), $roles->contains('role', 'centre_director')];
    }

    private function assertChild(int $agencyId, ?array $centreIds, int $child): void
    {
        $ok = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')->join('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->where('c.id', $child)->where('ce.agency_id', $agencyId)
            ->when($centreIds !== null, fn ($q) => $q->whereIn('f.centre_id', $centreIds ?: [0]))->exists();
        abort_unless($ok, 404, 'That child was not found.');
    }

    private function audit(Request $request, int $childId, string $action, string $summary): void
    {
        try {
            $agency = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')->join('centres as ce', 'ce.id', '=', 'f.centre_id')
                ->where('c.id', $childId)->value('ce.agency_id');
            Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agency ? (int) $agency : null, 'action' => $action,
                'entity_type' => 'child', 'entity_id' => $childId, 'payload' => json_encode(['summary' => $summary]), 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
