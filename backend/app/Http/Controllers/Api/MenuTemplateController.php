<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Live;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reusable menu weeks (2026-11-03).
 *
 *   GET    /operations/menu/templates               the agency's saved weeks + the centres you may apply them to
 *   POST   /operations/menu/templates               {centre_id, week_start, name} save that SAVED week as a template
 *   POST   /operations/menu/templates/{id}/apply    {centre_ids[], week_start, replace?} copy it into those centres' weeks, as drafts
 *   DELETE /operations/menu/templates/{id}          the person who saved it, or a director / admin
 *
 * Templates belong to the ACTIVE agency. A template keeps its dishes' food photos: they were
 * accepted by OperationsController::saveMenuWeek (library photos or this agency's uploads), and
 * they are only ever applied inside the same agency.
 *
 * WHERE IT CAN GO: the centres you work in - the same list the menu's provider picker shows
 * (OperationsController::myCentres): agency admins and platform admins every open centre of the
 * agency; directors and educators the centres on their own role rows. Every centre in the
 * request is checked, and the whole request is refused if any one is outside that list.
 * Applied weeks are DRAFTS, and a published week is never overwritten, so families are never
 * shown a menu nobody reviewed.
 */
final class MenuTemplateController extends Controller
{
    private const MEALS = ['breakfast', 'morning_snack', 'lunch', 'afternoon_snack', 'dinner'];

    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        $rows = DB::table('menu_templates as t')->leftJoin('users as u', 'u.id', '=', 't.created_by_id')
            ->where('t.agency_id', $agencyId)->orderBy('t.name')
            ->get(['t.id', 't.name', 't.item_count', 't.use_count', 't.items', 't.source_week', 't.created_by_id', 't.created_at', 'u.first_name']);
        $uid = (int) $request->user()->id;
        $manage = $this->canManage($request, $agencyId);
        $country = strtoupper(trim((string) DB::table('agencies')->where('id', $agencyId)->value('country')));

        return response()->json([
            'templates' => $rows->map(function ($t) use ($uid, $manage) {
                $items = json_decode((string) $t->items, true) ?: [];
                $thumbs = [];
                foreach ($items as $it) { if (! empty($it['image_url']) && count($thumbs) < 4) $thumbs[] = $it['image_url']; }
                return ['id' => (int) $t->id, 'name' => $t->name, 'item_count' => (int) $t->item_count, 'use_count' => (int) $t->use_count,
                    'source_week' => $t->source_week, 'created_by' => $t->first_name, 'created_at' => $t->created_at,
                    'thumbs' => $thumbs, 'can_delete' => $manage || (int) $t->created_by_id === $uid,
                    'preview' => array_slice(array_map(fn ($it) => ['d' => (int) $it['day_of_week'], 'm' => $it['meal_type'], 'n' => $it['name']], $items), 0, 40)];
            })->values(),
            'centres' => $this->scopeCentres($request, $agencyId),
            // Canada's Food Guide check is for Canadian (and unset) agencies; US agencies keep CACFP as it is.
            'food_guide' => ! in_array($country, ['US', 'USA', 'UNITED STATES', 'UNITED STATES OF AMERICA'], true),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['centre_id' => 'required|integer', 'week_start' => 'required|date', 'name' => 'required|string|max:80']);
        $agencyId = $this->agency($request);
        $centreId = (int) $d['centre_id'];
        abort_unless(in_array($centreId, array_column($this->scopeCentres($request, $agencyId), 'id'), true), 403, 'You cannot use that centre\'s menu.');
        $week = DB::table('menu_weeks')->where('centre_id', $centreId)->where('week_start', Carbon::parse($d['week_start'])->toDateString())->first();
        $items = $week ? DB::table('menu_items')->where('menu_week_id', $week->id)->orderBy('day_of_week')->orderBy('meal_type')
            ->get(['day_of_week', 'meal_type', 'name', 'description', 'allergens', 'image_url', 'image_source', 'food_image_id'])
            ->map(fn ($r) => (array) $r)->all() : [];
        if (! $items) {
            return response()->json(['message' => 'This week has no saved dishes yet. Save the menu first, then save it as a template.'], 422);
        }
        $name = trim($d['name']);
        if (DB::table('menu_templates')->where('agency_id', $agencyId)->where('name', $name)->exists()) {
            return response()->json(['message' => 'There is already a template called "' . $name . '". Choose another name.'], 422);
        }
        $id = DB::table('menu_templates')->insertGetId([
            'agency_id' => $agencyId, 'name' => $name, 'source_centre_id' => $centreId, 'source_week' => $week->week_start,
            'items' => json_encode(array_values($items), JSON_UNESCAPED_UNICODE), 'item_count' => count($items), 'use_count' => 0,
            'created_by_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        Audit::write(['user_id' => $request->user()->id, 'agency_id' => $agencyId, 'action' => 'menu_template.saved',
            'entity_type' => 'menu_template', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Saved the menu week of ' . $week->week_start . ' as the template "' . $name . '"', 'name' => $name,
                'centre_id' => $centreId, 'week_start' => $week->week_start, 'dishes' => count($items)]), 'created_at' => now()]);

        return response()->json(['id' => $id, 'item_count' => count($items)], 201);
    }

    public function apply(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['centre_ids' => 'required|array|min:1|max:60', 'centre_ids.*' => 'integer',
            'week_start' => 'required|date', 'replace' => 'nullable|boolean']);
        $agencyId = $this->agency($request);
        $tpl = DB::table('menu_templates')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($tpl, 404, 'That template was not found.');
        $allowed = array_column($this->scopeCentres($request, $agencyId), 'name', 'id');
        $targets = array_values(array_unique(array_map('intval', $d['centre_ids'])));
        $outside = array_values(array_filter($targets, fn ($c) => ! isset($allowed[$c])));
        if ($outside) {
            return response()->json(['message' => 'You can only apply a menu to centres you work in.', 'outside' => $outside], 403);
        }
        $weekStart = Carbon::parse($d['week_start'])->startOfWeek(Carbon::MONDAY)->toDateString();
        $items = json_decode((string) $tpl->items, true) ?: [];
        $replace = ! empty($d['replace']);
        $done = []; $skipped = [];
        DB::transaction(function () use ($targets, $weekStart, $items, $replace, $request, &$done, &$skipped, $allowed) {
            foreach ($targets as $cid) {
                $week = DB::table('menu_weeks')->where('centre_id', $cid)->where('week_start', $weekStart)->lockForUpdate()->first();
                $count = $week ? DB::table('menu_items')->where('menu_week_id', $week->id)->count() : 0;
                if ($week && $week->status === 'published' && $count > 0) {
                    $skipped[] = ['centre_id' => $cid, 'name' => $allowed[$cid], 'reason' => 'already published — open that week to change it'];
                    continue;
                }
                if ($count > 0 && ! $replace) {
                    $skipped[] = ['centre_id' => $cid, 'name' => $allowed[$cid], 'reason' => 'already has a draft menu'];
                    continue;
                }
                if ($week) {
                    DB::table('menu_weeks')->where('id', $week->id)->update(['status' => 'draft', 'published_at' => null, 'updated_at' => now()]);
                    DB::table('menu_items')->where('menu_week_id', $week->id)->delete();
                    $weekId = (int) $week->id;
                } else {
                    $weekId = (int) DB::table('menu_weeks')->insertGetId(['centre_id' => $cid, 'week_start' => $weekStart, 'status' => 'draft',
                        'notes' => null, 'meal_times' => null, 'created_by_id' => $request->user()->id, 'published_at' => null,
                        'created_at' => now(), 'updated_at' => now()]);
                }
                foreach ($items as $it) {
                    if (! in_array($it['meal_type'] ?? '', self::MEALS, true) || (int) ($it['day_of_week'] ?? 0) < 1 || (int) $it['day_of_week'] > 7) continue;
                    DB::table('menu_items')->insert([
                        'menu_week_id' => $weekId, 'day_of_week' => (int) $it['day_of_week'], 'meal_type' => $it['meal_type'],
                        'name' => mb_substr((string) $it['name'], 0, 255), 'description' => $it['description'] ?? null,
                        'allergens' => $it['allergens'] ?? null, 'image_url' => $it['image_url'] ?? null,
                        'image_source' => $it['image_source'] ?? null, 'food_image_id' => $it['food_image_id'] ?? null]);
                }
                $done[] = ['centre_id' => $cid, 'name' => $allowed[$cid], 'week_id' => $weekId];
            }
        });
        if ($done) DB::table('menu_templates')->where('id', $id)->update(['use_count' => DB::raw('use_count + 1'), 'updated_at' => now()]);
        Audit::write(['user_id' => $request->user()->id, 'agency_id' => $agencyId, 'action' => 'menu_template.applied',
            'entity_type' => 'menu_template', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Applied the menu template "' . $tpl->name . '" to the week of ' . $weekStart . ' at ' . count($done) . ' centre(s), as a draft',
                'name' => $tpl->name, 'week_start' => $weekStart, 'centre_ids' => array_column($done, 'centre_id'),
                'skipped' => array_column($skipped, 'centre_id'), 'replace' => $replace]), 'created_at' => now()]);

        return response()->json(['applied' => $done, 'skipped' => $skipped, 'week_start' => $weekStart]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $tpl = DB::table('menu_templates')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($tpl, 404, 'That template was not found.');
        abort_unless($this->canManage($request, $agencyId) || (int) $tpl->created_by_id === (int) $request->user()->id, 403,
            'Only the person who saved it, a director or an admin can delete this template.');
        DB::table('menu_templates')->where('id', $id)->delete();
        Audit::write(['user_id' => $request->user()->id, 'agency_id' => $agencyId, 'action' => 'menu_template.deleted',
            'entity_type' => 'menu_template', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Deleted the menu template "' . $tpl->name . '"', 'name' => $tpl->name]), 'created_at' => now()]);

        return response()->json(['ok' => true]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Open centres of the ACTIVE agency that this person works in: [{id, name}]. */
    private function scopeCentres(Request $request, int $agencyId): array
    {
        $uid = (int) $request->user()->id;
        $q = Live::centres(DB::table('centres'))->where('agency_id', $agencyId);
        $wide = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->where(fn ($w) => $w->where('role', 'platform_admin')
                ->orWhere(fn ($x) => $x->where('role', 'agency_admin')->where('agency_id', $agencyId)))->exists();
        if (! $wide) {
            $ids = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
                ->whereIn('role', ['educator', 'centre_director'])->whereNotNull('centre_id')->pluck('centre_id')->map(fn ($v) => (int) $v)->all();
            $q->whereIn('id', $ids ?: [0]);
        }

        return $q->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name])->all();
    }

    private function agency(Request $request): int
    {
        $uid = (int) $request->user()->id;
        $header = (int) $request->header('X-Active-Agency-Id');
        $isPlatform = DB::table('role_assignments')->where('user_id', $uid)->where('role', 'platform_admin')->where('active', true)->exists();
        if ($header > 0 && ($isPlatform || \App\Support\AgencyRole::activeAgency($request) === $header)) return $header;
        abort_if($isPlatform, 400, 'Select an agency first.');
        $ids = DB::table('role_assignments as ra')->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
            ->where('ra.user_id', $uid)->where('ra.active', true)
            ->whereIn('ra.role', ['educator', 'centre_director', 'agency_admin'])
            ->selectRaw('DISTINCT COALESCE(ra.agency_id, c.agency_id) as a')->pluck('a')->filter()->unique()->values()->all();
        abort_unless(count($ids) === 1, 400, 'Select an agency first.');

        return (int) $ids[0];
    }

    private function canManage(Request $request, int $agencyId): bool
    {
        return DB::table('role_assignments')->where('user_id', (int) $request->user()->id)->where('active', true)
            ->where(fn ($w) => $w->where(fn ($x) => $x->where('agency_id', $agencyId)->whereIn('role', ['agency_admin', 'centre_director']))
                ->orWhere('role', 'platform_admin'))->exists();
    }
}
