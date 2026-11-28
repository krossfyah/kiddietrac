<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p58 — CACFP / Canadian child nutrition program meal-count tracking.
 * Each row = one (child, date, meal_type) served. Monthly report by tier
 * is the reimbursement-claim filing data.
 */
final class CacfpController extends Controller
{
    public function dailyRoster(Request $request): JsonResponse
    {
        $centreId = (int) $request->query('centre_id', 0);
        abort_unless($centreId, 400, 'centre_id required');
        // SECURITY (v22p96): the centre must belong to the caller's active agency
        // (platform_admin: the agency they've switched into). Was an unscoped read
        // by centre_id — any caller could enumerate any centre's roster + tier.
        $centreAgency = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
        abort_unless($centreAgency && $centreAgency === (int) $this->resolveAgencyId($request), 403);
        // Inside the caller's own centres (2026-11-28): a director or educator reads only a centre they work at.
        abort_unless(\App\Support\CentreScope::canRead(\App\Support\CentreScope::ids($request, $centreAgency), $centreId), 404);
        $date = Carbon::parse($request->query('date', \App\Support\AgencyTime::today()))->toDateString();

        /* Who is at THIS centre on THIS date — resolved through the enrolment, not
           through the family.

           This used to select on families.centre_id, which is the family's single
           anchor: every child of that family counted at that one centre every day. Once
           a family can split its week across providers that is wrong in both directions
           and it is a meal claim, so it is wrong with money attached — the anchor centre
           would claim for a child who was not there, and the other provider could not
           claim for a child who was. Aydan Rappitt is with Amna every day while his
           family is anchored to Cassandra; before this he appeared on Cassandra's
           claim sheet and never on Amna's. (2026-08-27) */
        $dayKey = \App\Support\CareSchedule::dayKey(
            \App\Support\AgencyTime::tzForCentre($centreId), $date
        );

        $children = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('enrollments as e', 'e.child_id', '=', 'ch.id')
            ->join('rooms as r', 'r.id', '=', 'e.room_id')
            ->where('r.centre_id', $centreId)
            ->whereNull('e.end_date')
            ->tap(fn ($q) => \App\Support\CareSchedule::constrain($q, 'e', $dayKey))
            ->where('ch.enrollment_status', 'enrolled')
            ->whereNull('ch.deleted_at')
            ->select('ch.id', 'ch.first_name', 'ch.last_name', 'f.cacfp_tier', 'f.id as family_id', 'f.family_name')
            ->distinct()
            ->orderBy('ch.last_name')->get();

        $meals = DB::table('cacfp_meals')->where('centre_id', $centreId)->where('meal_date', $date)->get();
        $byChild = $meals->groupBy('child_id');

        $tierSettings = \App\Support\CacfpTier::settingsFor($children->pluck('id')->all());
        $rows = $children->map(function ($ch) use ($byChild, $tierSettings, $date) {
            $cacheMeals = $byChild->get($ch->id, collect())->keyBy('meal_type');
            $s = $tierSettings[$ch->id] ?? null;
            $t = \App\Support\CacfpTier::describe($s, $date);
            return [
                'child_id' => $ch->id,
                'child_name' => $ch->first_name . ' ' . $ch->last_name,
                'family_id' => (int) $ch->family_id,
                'family_name' => $ch->family_name,
                'cacfp_tier' => $t['tier'],
                'tier' => $t,
                'family_tier' => $s->family_tier ?? null, 'family_eligibility_date' => $s->family_date ?? null, 'family_evidence_note' => $s->family_note ?? null,
                'child_tier' => $s->child_tier ?? null, 'child_eligibility_date' => $s->child_date ?? null, 'child_evidence_note' => $s->child_note ?? null,
                'breakfast' => isset($cacheMeals['breakfast']) ? (bool) $cacheMeals['breakfast']->served : false,
                'morning_snack' => isset($cacheMeals['morning_snack']) ? (bool) $cacheMeals['morning_snack']->served : false,
                'lunch' => isset($cacheMeals['lunch']) ? (bool) $cacheMeals['lunch']->served : false,
                'afternoon_snack' => isset($cacheMeals['afternoon_snack']) ? (bool) $cacheMeals['afternoon_snack']->served : false,
                'dinner' => isset($cacheMeals['dinner']) ? (bool) $cacheMeals['dinner']->served : false,
            ];
        });
        $canTier = (bool) array_intersect(\App\Support\AgencyRole::names($request), ['platform_admin', 'agency_admin', 'centre_director']);
        return response()->json(['date' => $date, 'centre_id' => $centreId, 'data' => $rows, 'can_set_tier' => $canTier]);
    }

    public function setMeal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => 'required|integer',
            'child_id' => 'required|integer',
            'meal_date' => 'required|date',
            'meal_type' => 'required|in:breakfast,morning_snack,lunch,afternoon_snack,dinner',
            'served' => 'required|boolean',
        ]);
        /* The centre must be one you work at, and the child must be at that centre (2026-11-05 leak sweep):
           child_id and centre_id were written unchecked, so any staff member could file meal claims for
           another agency's child, or into another agency's claim sheet. "At that centre" is what the roster
           shows: the family's centre, or an open enrolment in one of the centre's rooms (split-week care). */
        $agencyId = $this->resolveAgencyId($request);
        $centreAgency = (int) DB::table('centres')->where('id', $data['centre_id'])->value('agency_id');
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        if (! $centreAgency || $centreAgency !== $agencyId || ! \App\Support\CentreScope::canWrite($scope, (int) $data['centre_id'])) {
            return response()->json(['message' => 'You can only record meals at a centre you work at.'], 403);
        }
        $familyId = DB::table('children')->where('id', $data['child_id'])->whereNull('deleted_at')->value('family_id');
        $atCentre = $familyId && (
            (int) DB::table('families')->where('id', $familyId)->value('centre_id') === (int) $data['centre_id']
            || DB::table('enrollments as e')->join('rooms as r', 'r.id', '=', 'e.room_id')
                ->where('e.child_id', $data['child_id'])->whereNull('e.end_date')
                ->where('r.centre_id', $data['centre_id'])->exists()
        );
        if (! $atCentre) {
            return response()->json(['message' => 'That child is not enrolled at this centre.'], 403);
        }
        $family = DB::table('families')->where('id', $familyId)->first();
        DB::table('cacfp_meals')->updateOrInsert(
            [
                'child_id' => $data['child_id'],
                'meal_date' => $data['meal_date'],
                'meal_type' => $data['meal_type'],
            ],
            [
                'centre_id' => $data['centre_id'],
                'served' => $data['served'] ? 1 : 0,
                'eligibility_tier' => \App\Support\CacfpTier::on(\App\Support\CacfpTier::settingsFor([(int) $data['child_id']])[(int) $data['child_id']] ?? null, Carbon::parse($data['meal_date'])->toDateString()),
                'recorded_by_id' => $request->user()->id,
                'created_at' => now(),
            ]
        );
        return response()->json(['status' => 'saved']);
    }

    /**
     * The family's CACFP tier (2026-11-28): Tier I / Tier II (homes) or Free / Reduced / Paid (centres), the date
     * eligibility was determined and a note of the evidence seen (income form, SNAP/TANF letter...). Empty tier =
     * clear it. Directors only for families at their own centres. Served meals are re-stamped with the new tier.
     */
    public function setFamilyTier(Request $request, int $familyId): JsonResponse
    {
        $data = $this->tierInput($request);
        $agencyId = $this->resolveAgencyId($request);
        $fam = DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('f.id', $familyId)->select('c.agency_id', 'f.centre_id')->first();
        // The family must be in the ACTIVE agency (a platform admin too: the agency switched into), and a director's.
        abort_unless($fam && (int) $fam->agency_id === (int) $agencyId, 404);
        $this->assertTierCentre($request, $agencyId, $this->familyCentres($familyId, (int) $fam->centre_id));
        DB::table('families')->where('id', $familyId)->update([
            'cacfp_tier' => $data['tier'],
            'cacfp_eligibility_date' => $data['tier'] ? $data['date'] : null,
            'cacfp_evidence_note' => $data['tier'] ? $data['note'] : null,
            'updated_at' => now(),
        ]);
        $kids = DB::table('children')->where('family_id', $familyId)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $n = \App\Support\CacfpTier::restamp($kids);
        return response()->json(['status' => 'updated', 'meals_updated' => $n]);
    }

    /** One child's own tier, over the family's (a foster child, a Tier I child in a Tier II home). Empty = use the family's. */
    public function setChildTier(Request $request, int $childId): JsonResponse
    {
        $data = $this->tierInput($request);
        $agencyId = $this->resolveAgencyId($request);
        $ch = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('ch.id', $childId)->whereNull('ch.deleted_at')->select('c.agency_id', 'f.centre_id', 'ch.family_id')->first();
        abort_unless($ch && (int) $ch->agency_id === (int) $agencyId, 404);
        $this->assertTierCentre($request, $agencyId, $this->familyCentres((int) $ch->family_id, (int) $ch->centre_id));
        DB::table('children')->where('id', $childId)->update([
            'cacfp_tier' => $data['tier'],
            'cacfp_eligibility_date' => $data['tier'] ? $data['date'] : null,
            'cacfp_evidence_note' => $data['tier'] ? $data['note'] : null,
            'updated_at' => now(),
        ]);
        $n = \App\Support\CacfpTier::restamp([$childId]);
        return response()->json(['status' => 'updated', 'meals_updated' => $n]);
    }

    private function tierInput(Request $request): array
    {
        $data = $request->validate([
            'cacfp_tier' => 'nullable|string|in:' . implode(',', array_keys(\App\Support\CacfpTier::TIERS)),
            'eligibility_date' => 'nullable|date',
            'evidence_note' => 'nullable|string|max:1000',
        ]);
        $tier = $data['cacfp_tier'] ?? null;
        $date = ! empty($data['eligibility_date']) ? Carbon::parse($data['eligibility_date'])->toDateString() : null;
        if ($tier && in_array($tier, \App\Support\CacfpTier::INCOME_BASED, true) && ! $date) {
            abort(response()->json(['message' => 'Enter the date eligibility was determined. ' . \App\Support\CacfpTier::TIERS[$tier] . ' counts for 12 months from it.'], 422));
        }
        $note = trim((string) ($data['evidence_note'] ?? ''));
        return ['tier' => $tier ?: null, 'date' => $date, 'note' => $note !== '' ? $note : null];
    }

    /** The centres a family is at: its anchor centre plus any centre of an open enrolment (split-week care). */
    private function familyCentres(int $familyId, int $anchor): array
    {
        $ids = DB::table('enrollments as e')->join('rooms as r', 'r.id', '=', 'e.room_id')->join('children as ch', 'ch.id', '=', 'e.child_id')
            ->where('ch.family_id', $familyId)->whereNull('e.end_date')->pluck('r.centre_id')->map(fn ($v) => (int) $v)->all();
        return array_values(array_unique(array_merge([$anchor], $ids)));
    }

    private function assertTierCentre(Request $request, int $agencyId, array $centreIds): void
    {
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        if ($scope === null) return;
        foreach ($centreIds as $c) if (in_array((int) $c, $scope, true)) return;
        abort(response()->json(['message' => 'You can set the tier only for families at your own centres.'], 403));
    }

    /**
     * The monthly CACFP claim for ONE centre (2026-11-28): meals served by type, tier and day, the totals a sponsor's
     * claim form asks for (operating days, enrolment by tier, average daily participation, meals by type x tier) and
     * the children with no tier on file. The screen makes the CSV and the printout from this.
     */
    public function claim(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $month = (string) $request->query('month', \App\Support\AgencyTime::todayDate()->format('Y-m'));
        abort_unless(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month), 422, 'Choose a month.');
        $centreId = (int) $request->query('centre_id', 0);
        $centre = DB::table('centres')->where('id', $centreId)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        abort_unless($centre, 404);
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        abort_unless(\App\Support\CentreScope::canRead($scope, $centreId), 404);

        $start = Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfDay();
        $end = $start->copy()->endOfMonth();
        [$s, $e] = [$start->toDateString(), $end->toDateString()];
        $tiers = array_merge(array_keys(\App\Support\CacfpTier::TIERS), ['none']);
        $mealTypes = array_keys(\App\Support\CacfpTier::MEALS);
        $zero = array_fill_keys($tiers, 0);

        $meals = DB::table('cacfp_meals')->where('centre_id', $centreId)->whereBetween('meal_date', [$s, $e])->where('served', 1)
            ->get(['child_id', 'meal_date', 'meal_type', 'eligibility_tier']);
        $days = [];
        $totals = array_fill_keys($mealTypes, $zero);
        foreach ($meals as $m) {
            $d = substr((string) $m->meal_date, 0, 10);
            $t = in_array($m->eligibility_tier, $tiers, true) ? $m->eligibility_tier : 'none';
            if (! isset($totals[$m->meal_type])) continue;
            if (! isset($days[$d])) $days[$d] = ['date' => $d, 'meals' => array_fill_keys($mealTypes, $zero), 'children' => []];
            $days[$d]['meals'][$m->meal_type][$t]++;
            $days[$d]['children'][(int) $m->child_id] = true;
            $totals[$m->meal_type][$t]++;
        }
        ksort($days);
        $days = array_values(array_map(function ($d) { $d['children_served'] = count($d['children']); unset($d['children']); return $d; }, $days));
        $byTier = $zero;
        foreach ($totals as $row) foreach ($row as $t => $n) $byTier[$t] += $n;
        $operating = count($days);
        $served = array_sum(array_column($days, 'children_served'));

        // Enrolled at this centre during the month, with the tier that applied on the last day of it (or today).
        $asOf = min($e, \App\Support\AgencyTime::today());
        $kids = DB::table('children as ch')->join('enrollments as en', 'en.child_id', '=', 'ch.id')->join('rooms as r', 'r.id', '=', 'en.room_id')
            ->where('r.centre_id', $centreId)->whereNull('ch.deleted_at')
            ->where(fn ($q) => $q->whereNull('en.start_date')->orWhere('en.start_date', '<=', $e))
            ->where(fn ($q) => $q->whereNull('en.end_date')->orWhere('en.end_date', '>=', $s))
            ->distinct()->orderBy('ch.last_name')->orderBy('ch.first_name')->get(['ch.id', 'ch.first_name', 'ch.last_name']);
        $settings = \App\Support\CacfpTier::settingsFor($kids->pluck('id')->all());
        $enrolled = $zero;
        $missing = [];
        foreach ($kids as $k) {
            $t = \App\Support\CacfpTier::describe($settings[$k->id] ?? null, $asOf);
            $enrolled[$t['tier'] ?? 'none']++;
            if (! $t['tier']) $missing[] = ['child_id' => (int) $k->id, 'name' => trim($k->first_name . ' ' . $k->last_name), 'reason' => $t['expired'] ? 'expired' : ($t['set'] ? 'not_yet' : 'unset')];
        }

        return response()->json([
            'month' => $month, 'from' => $s, 'to' => $e,
            'agency' => (string) DB::table('agencies')->where('id', $agencyId)->value('name'),
            'centre' => ['id' => (int) $centre->id, 'name' => $centre->name, 'license_number' => $centre->license_number,
                'address' => trim(implode(', ', array_filter([$centre->address_line1, $centre->city, $centre->province, $centre->postal_code])))],
            'tiers' => \App\Support\CacfpTier::TIERS + ['none' => 'No tier'],
            'meal_types' => \App\Support\CacfpTier::MEALS,
            'operating_days' => $operating,
            'enrolled' => $enrolled, 'enrolled_total' => array_sum($enrolled),
            'average_daily_participation' => $operating ? round($served / $operating, 1) : 0,
            'totals' => $totals, 'by_tier' => $byTier, 'grand_total' => array_sum($byTier),
            'days' => $days,
            'no_tier_children' => $missing,
        ]);
    }

    public function monthlyReport(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $month = $request->query('month', Carbon::now()->format('Y-m'));
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        /* Per centre, inside the caller's own centres (2026-11-27): the month tiles were agency-wide, so a director
           saw every centre's meal counts next to their own roster. centre_id narrows to the centre on screen. */
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        $centreId = (int) $request->query('centre_id', 0);
        if ($centreId && $scope !== null && ! in_array($centreId, $scope, true)) abort(404);
        $rows = DB::table('cacfp_meals as cm')
            ->join('centres as c', 'c.id', '=', 'cm.centre_id')
            ->where('c.agency_id', $agencyId)
            ->when($scope !== null, fn ($q) => $q->whereIn('c.id', $scope ?: [0]))
            ->when($centreId > 0, fn ($q) => $q->where('c.id', $centreId))
            ->whereBetween('cm.meal_date', [$start, $end])
            ->where('cm.served', 1)
            ->select('cm.meal_type', 'cm.eligibility_tier', 'c.name as centre_name',
                DB::raw('COUNT(*) as meal_count'))
            ->groupBy('cm.meal_type', 'cm.eligibility_tier', 'c.name')
            ->get();

        $totals = [
            'tier1' => $rows->where('eligibility_tier', 'tier1')->sum('meal_count'),
            'tier2' => $rows->where('eligibility_tier', 'tier2')->sum('meal_count'),
            'free' => $rows->where('eligibility_tier', 'free')->sum('meal_count'),
            'reduced' => $rows->where('eligibility_tier', 'reduced')->sum('meal_count'),
            'paid' => $rows->where('eligibility_tier', 'paid')->sum('meal_count'),
            'no_tier' => $rows->whereNull('eligibility_tier')->sum('meal_count'),
        ];
        $totals['grand_total'] = array_sum($totals);
        return response()->json(['month' => $month, 'data' => $rows, 'totals' => $totals]);
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
            ->where('user_id', $request->user()->id)->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400);
        return (int) $first;
    }
}
