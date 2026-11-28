<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p56 — Procare-parity billing:
 *   - Vacation hold credits
 *   - Tuition increase scheduling + advance notification
 *   - Pro-rated tuition for partial months
 */
final class BillingV2Controller extends Controller
{
    // ===================================
    // Vacation holds (pause tuition)
    // ===================================
    public function listHolds(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        /* Centre scope + live families (2026-11-27, help-writer check): the list filtered by agency only, so a centre
           director saw every centre's holds, and families that had left or whose centre had closed stayed listed. */
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        $q = DB::table('vacation_holds as vh')
            ->join('families as f', 'f.id', '=', 'vh.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('c.agency_id', $agencyId);
        \App\Support\Live::families($q, 'f');
        \App\Support\Live::centres($q, 'c');
        if ($scope !== null) {
            $q->whereIn('f.centre_id', $scope ?: [0]);
        }
        $rows = $q->orderByDesc('vh.start_date')
            ->select('vh.*', 'f.family_name', 'c.name as centre_name')
            ->limit(200)
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function requestHold(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'nullable|string|max:120',
        ]);
        $u = $request->user();
        $familyId = DB::table('guardians')->where('user_id', $u->id)->value('family_id');
        abort_unless($familyId, 403, 'No family linked');
        $id = DB::table('vacation_holds')->insertGetId([
            'family_id' => $familyId,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'reason' => $data['reason'] ?? null,
            'status' => 'requested',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Notify directors
        $family = DB::table('families')->where('id', $familyId)->first();
        $directorIds = DB::table('role_assignments')
            ->where('centre_id', $family->centre_id)
            ->whereIn('role', ['centre_director', 'agency_admin'])
            ->where('active', 1)
            ->pluck('user_id')->unique();
        foreach ($directorIds as $did) {
            \App\Support\Notify::write([
                'user_id' => $did, 'type' => 'vacation_hold',
                'title' => "Vacation hold requested by " . ($family->family_name ?? 'a family'),
                'body' => Carbon::parse($data['start_date'])->format('M j') . ' – ' . Carbon::parse($data['end_date'])->format('M j'),
                'data' => json_encode(['link' => '#vacation-holds', 'hold_id' => $id]),
                'created_at' => now(),
            ]);
        }
        return response()->json(['id' => $id], 201);
    }

    /** Staff/director adds a vacation hold on behalf of a specific family. */
    public function createForFamily(Request $request): JsonResponse
    {
        $data = $request->validate([
            'family_id' => 'required|integer',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'nullable|string|max:120',
        ]);
        $family = DB::table('families')->where('id', $data['family_id'])->whereNull('deleted_at')->first();
        abort_unless($family, 404, 'Family not found');
        $agencyId = (int) DB::table('centres')->where('id', $family->centre_id)->value('agency_id');
        $ok = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
            ->where(function ($q) use ($agencyId, $family) {
                $q->where('agency_id', $agencyId)->orWhere('centre_id', $family->centre_id)->orWhere('role', 'platform_admin');
            })->exists();
        abort_unless($ok, 403, 'Forbidden');
        /* Same rule as deciding a hold (2026-11-05 leak sweep): the check above accepted any role in the
           family's agency (an educator, a director of another centre) and a platform admin in any agency. */
        abort_unless($this->mayDecideForFamily($request, (int) $data['family_id']), 403, 'Forbidden');
        $id = DB::table('vacation_holds')->insertGetId([
            'family_id' => $data['family_id'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'reason' => $data['reason'] ?? null,
            'status' => 'requested',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $gids = DB::table('guardians')->where('family_id', $data['family_id'])->pluck('user_id');
        foreach ($gids as $gid) {
            \App\Support\Notify::write([
                'user_id' => $gid, 'type' => 'vacation_hold',
                'title' => 'A vacation hold was added to your account',
                'body' => Carbon::parse($data['start_date'])->format('M j') . ' – ' . Carbon::parse($data['end_date'])->format('M j'),
                'data' => json_encode(['link' => '#billing', 'hold_id' => $id]),
                'created_at' => now(),
            ]);
        }
        return response()->json(['id' => $id], 201);
    }

    public function decideHold(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:approved,denied',
            'credit_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);
        $hold = DB::table('vacation_holds')->where('id', $id)->first();
        abort_unless($hold, 404);
        /* The hold's family must be at a centre you direct, in this agency (2026-11-05 leak sweep): the hold was
           changed by id with no scope, so any director or admin could approve (and set the credit on) another
           centre's or another agency's vacation hold. */
        abort_unless($this->mayDecideForFamily($request, (int) $hold->family_id), 403);
        // Auto-compute credit if approved + no override given
        $credit = $data['credit_amount'] ?? null;
        if ($data['status'] === 'approved' && $credit === null) {
            $days = Carbon::parse($hold->end_date)->diffInDays(Carbon::parse($hold->start_date)) + 1;
            // estimate $30/day daily rate as fallback
            $credit = $days * 30;
        }
        DB::table('vacation_holds')->where('id', $id)->update([
            'status' => $data['status'],
            'decided_by_id' => $request->user()->id,
            'decided_at' => now(),
            'credit_amount' => $credit,
            'notes' => $data['notes'] ?? null,
            'updated_at' => now(),
        ]);
        // Notify requester
        $gids = DB::table('guardians')->where('family_id', $hold->family_id)->pluck('user_id');
        foreach ($gids as $gid) {
            \App\Support\Notify::write([
                'user_id' => $gid, 'type' => 'vacation_hold',
                'title' => "Vacation hold " . $data['status'],
                'body' => $data['status'] === 'approved' ? "Credit of $" . number_format((float) $credit, 2) . " will be applied." : ($data['notes'] ?? 'Unable to approve at this time.'),
                'data' => json_encode(['link' => '#billing', 'hold_id' => $id]),
                'created_at' => now(),
            ]);
        }
        return response()->json(['status' => $data['status'], 'credit_amount' => $credit]);
    }

    // ===================================
    // Tuition increase scheduling
    // ===================================
    /* Scheduled -> Notified -> Applied, run by billing:tuition-increases (App\Support\TuitionIncreases, 2026-11-28). */
    public function listIncreases(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        /* Centre scope (2026-11-27): a director sees their own centres' increases and the agency-wide ones (they apply
           to their centre too), never another centre's. Increases for a closed centre are not listed. */
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        $open = \App\Support\Live::centreIds($agencyId);
        $visible = $scope === null ? $open : array_values(array_intersect($open, $scope));
        $rows = DB::table('tuition_increases as ti')->leftJoin('centres as c', 'c.id', '=', 'ti.centre_id')
            ->where('ti.agency_id', $agencyId)
            ->where(fn ($w) => $w->whereNull('ti.centre_id')->orWhereIn('ti.centre_id', $visible ?: [0]))
            ->orderByDesc('ti.effective_date')->select('ti.*', 'c.name as centre_name')->get();
        $today = \App\Support\AgencyTime::today($agencyId);
        $T = \App\Support\TuitionIncreases::class;
        $rows = $rows->map(function ($t) use ($today, $scope, $T) {
            $t->increase_type = $T::type($t->increase_type);
            $t->status = $T::status($t, $today);
            $t->notice_date = $T::noticeDate((string) $t->effective_date, (int) $t->notify_families_days_before);
            // Changed only while nothing has gone out; a director never changes an agency-wide one.
            $mine = \App\Support\CentreScope::canWrite($scope, $t->centre_id ? (int) $t->centre_id : null);
            $t->can_edit = $mine && $t->status === 'scheduled';
            $t->can_delete = $mine && in_array($t->status, ['scheduled', 'overdue'], true);
            $t->can_apply = $mine && $t->status === 'overdue';
            return $t;
        });
        return response()->json(['data' => $rows, 'today' => $today,
            'notice_days' => \App\Support\BillingRules::tuitionNoticeDays($agencyId)]);
    }

    /** Before saving: which families, which children, old -> new. Same rules as the scheduler, from today's fees. */
    public function previewIncrease(Request $request): JsonResponse
    {
        [$agencyId, $d, $centreId, $err] = $this->increaseInput($request, null);
        if ($err) return $err;
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        $aff = \App\Support\TuitionIncreases::affected($agencyId, $centreId, $d['increase_type'], (float) $d['amount'], $d['effective_date'], $scope);
        $days = (int) $d['notify_families_days_before'];
        $today = \App\Support\AgencyTime::today($agencyId);
        $noticeOn = \App\Support\TuitionIncreases::noticeDate($d['effective_date'], $days);
        return response()->json(['data' => $aff + [
            'notice_date' => $noticeOn,
            'notice_days' => $days,
            // Told later than promised if the notice day has already passed.
            'short_notice' => $noticeOn < $today,
            'days_of_notice' => (int) \Illuminate\Support\Carbon::parse($today)->diffInDays(\Illuminate\Support\Carbon::parse($d['effective_date'])),
        ]]);
    }

    public function scheduleIncrease(Request $request): JsonResponse
    {
        [$agencyId, $d, $centreId, $err] = $this->increaseInput($request, null);
        if ($err) return $err;
        $id = DB::table('tuition_increases')->insertGetId([
            'agency_id' => $agencyId,
            'centre_id' => $centreId,
            'effective_date' => $d['effective_date'],
            'increase_type' => $d['increase_type'],
            'amount' => $d['amount'],
            'notify_families_days_before' => $d['notify_families_days_before'],
            'notes' => $d['notes'] ?? null,
            'created_by_id' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['id' => $id], 201);
    }

    /** One increase: who was told / what changed (from the record), or who it will reach (from today's fees). */
    public function showIncrease(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $t = DB::table('tuition_increases as ti')->leftJoin('centres as c', 'c.id', '=', 'ti.centre_id')
            ->where('ti.id', $id)->where('ti.agency_id', $agencyId)->select('ti.*', 'c.name as centre_name')->first();
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        if (! $t || ! \App\Support\CentreScope::canRead($scope, $t->centre_id ? (int) $t->centre_id : null, true)) {
            return response()->json(['message' => 'That increase was not found.'], 404);
        }
        $T = \App\Support\TuitionIncreases::class;
        $t->increase_type = $T::type($t->increase_type);
        $t->status = $T::status($t, \App\Support\AgencyTime::today($agencyId));
        $t->notice_date = $T::noticeDate((string) $t->effective_date, (int) $t->notify_families_days_before);
        $phase = $t->applied_at ? 'applied' : ($t->notification_sent_at ? 'notice' : null);
        if ($phase) {
            $q = DB::table('tuition_increase_items as i')->leftJoin('families as f', 'f.id', '=', 'i.family_id')
                ->leftJoin('children as ch', 'ch.id', '=', 'i.child_id')->leftJoin('fee_plans as p', 'p.id', '=', 'i.fee_plan_id')
                ->where('i.tuition_increase_id', $id)->where('i.phase', $phase);
            // A director sees only their own centres' families on an agency-wide increase.
            if ($scope !== null) $q->where(fn ($w) => $w->whereNull('i.family_id')->orWhereIn('f.centre_id', $scope ?: [0]));
            $rows = $q->orderBy('f.family_name')->get(['i.family_id', 'f.family_name', 'ch.first_name', 'ch.preferred_name', 'i.fee_plan_id', 'p.name as plan_name', 'i.old_amount', 'i.new_amount']);
            $fams = [];
            $plans = [];
            foreach ($rows as $r) {
                if ($r->fee_plan_id) { $plans[] = ['name' => (string) $r->plan_name, 'old' => (float) $r->old_amount, 'new' => (float) $r->new_amount]; continue; }
                $fid = (int) $r->family_id;
                $fams[$fid] ??= ['family_id' => $fid, 'family_name' => (string) $r->family_name, 'children' => []];
                $fams[$fid]['children'][] = ['name' => trim((string) ($r->preferred_name ?: $r->first_name)) ?: 'Child', 'old' => (float) $r->old_amount, 'new' => (float) $r->new_amount];
            }
            $aff = ['families' => array_values($fams), 'plans' => $plans, 'skipped_plans' => [], 'from_record' => true];
        } else {
            $aff = $T::affected($agencyId, $t->centre_id ? (int) $t->centre_id : null, $t->increase_type, (float) $t->amount, substr((string) $t->effective_date, 0, 10), $scope) + ['from_record' => false];
        }
        return response()->json(['data' => $t, 'affected' => $aff]);
    }

    /** Edit while Scheduled (nothing sent yet). */
    public function updateIncrease(Request $request, int $id): JsonResponse
    {
        $t = $this->ownIncrease($request, $id);
        if ($t instanceof JsonResponse) return $t;
        if (\App\Support\TuitionIncreases::status($t) !== 'scheduled') {
            return response()->json(['message' => 'Families have already been told about this increase, so it can no longer be changed.'], 422);
        }
        [, $d, $centreId, $err] = $this->increaseInput($request, $t);
        if ($err) return $err;
        DB::table('tuition_increases')->where('id', $id)->whereNull('notification_sent_at')->update([
            'centre_id' => $centreId,
            'effective_date' => $d['effective_date'],
            'increase_type' => $d['increase_type'],
            'amount' => $d['amount'],
            'notify_families_days_before' => $d['notify_families_days_before'],
            'notes' => $d['notes'] ?? null,
            'updated_by_id' => $request->user()->id,
            'updated_at' => now(),
        ]);
        return response()->json(['status' => 'updated']);
    }

    /** Delete while Scheduled, or an Overdue one nobody applied. */
    public function deleteIncrease(Request $request, int $id): JsonResponse
    {
        $t = $this->ownIncrease($request, $id);
        if ($t instanceof JsonResponse) return $t;
        $st = \App\Support\TuitionIncreases::status($t, \App\Support\AgencyTime::today((int) $t->agency_id));
        if ($t->applied_at || ($t->notification_sent_at && $st !== 'overdue')) {
            return response()->json(['message' => $t->applied_at
                ? 'This increase has already been applied to families\' fees, so it cannot be deleted.'
                : 'Families have already been told about this increase, so it cannot be deleted.'], 422);
        }
        DB::table('tuition_increase_items')->where('tuition_increase_id', $id)->delete();
        DB::table('tuition_increases')->where('id', $id)->delete();
        return response()->json(['status' => 'deleted']);
    }

    /** An Overdue increase (its date passed and the scheduler would not apply it late): tell families, then apply. */
    public function applyIncreaseNow(Request $request, int $id): JsonResponse
    {
        $t = $this->ownIncrease($request, $id);
        if ($t instanceof JsonResponse) return $t;
        if (\App\Support\TuitionIncreases::status($t, \App\Support\AgencyTime::today((int) $t->agency_id)) !== 'overdue') {
            return response()->json(['message' => 'Only an overdue increase can be applied by hand. Others apply themselves on their date.'], 422);
        }
        $told = $t->notification_sent_at ? 0 : \App\Support\TuitionIncreases::notify($id);
        $changed = \App\Support\TuitionIncreases::apply($id);
        return response()->json(['status' => 'applied', 'families_told' => $told, 'fees_changed' => $changed]);
    }

    /** @return object|JsonResponse the increase, if it is in this agency and the caller may change it */
    private function ownIncrease(Request $request, int $id)
    {
        $agencyId = $this->resolveAgencyId($request);
        $t = DB::table('tuition_increases')->where('id', $id)->where('agency_id', $agencyId)->first();
        if (! $t) return response()->json(['message' => 'That increase was not found.'], 404);
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        if (! \App\Support\CentreScope::canWrite($scope, $t->centre_id ? (int) $t->centre_id : null)) {
            return response()->json(['message' => $t->centre_id ? 'You can only change increases for your own centres.' : 'Only an agency admin can change an increase for all centres.'], 403);
        }
        return $t;
    }

    /**
     * The shared checks for preview / schedule / edit, with friendly messages.
     * @return array{0:int, 1:array, 2:?int, 3:?JsonResponse}
     */
    private function increaseInput(Request $request, ?object $existing): array
    {
        $agencyId = $this->resolveAgencyId($request);
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'centre_id' => 'nullable|integer',
            'effective_date' => 'required|date_format:Y-m-d',
            'increase_type' => 'required|in:percent,flat',
            'amount' => 'required|numeric|gt:0',
            'notify_families_days_before' => 'nullable|integer|min:0|max:365',
            'notes' => 'nullable|string|max:2000',
        ], [
            'effective_date.required' => 'Choose the date the new tuition starts.',
            'effective_date.date_format' => 'Choose the date the new tuition starts.',
            'increase_type.required' => 'Choose a percent or a flat amount.',
            'increase_type.in' => 'Choose a percent or a flat amount.',
            'amount.required' => 'Enter the increase.',
            'amount.numeric' => 'The increase must be a number, without % or $.',
            'amount.gt' => 'The increase must be more than zero.',
            'notify_families_days_before.*' => 'Notice must be between 0 and 365 days.',
        ]);
        if ($v->fails()) {
            return [$agencyId, [], null, response()->json(['message' => $v->errors()->first(), 'errors' => $v->errors()], 422)];
        }
        $d = $v->validated();
        if ($d['increase_type'] === 'percent' && (float) $d['amount'] > 50) {
            return [$agencyId, [], null, response()->json(['message' => 'A percent increase can be at most 50%. Check the amount, or choose Flat amount.'], 422)];
        }
        if ($d['increase_type'] === 'flat' && (float) $d['amount'] > 5000) {
            return [$agencyId, [], null, response()->json(['message' => 'A flat increase can be at most $5,000 a month. Check the amount.'], 422)];
        }
        $today = \App\Support\AgencyTime::today($agencyId);
        if ($d['effective_date'] <= $today) {
            return [$agencyId, [], null, response()->json(['message' => 'The new tuition must start after today.', 'errors' => ['effective_date' => ['Must be after today.']]], 422)];
        }
        $d['notify_families_days_before'] = isset($d['notify_families_days_before'])
            ? (int) $d['notify_families_days_before']
            : ($existing ? (int) $existing->notify_families_days_before : \App\Support\BillingRules::tuitionNoticeDays($agencyId));
        /* Authorise the destination (2026-11-27): centre_id was written unchecked (any agency's centre), and a director
           with no centre_id created an AGENCY-WIDE increase. A director must name one of their own open centres (their
           only one is used when they have just one); an agency admin may name any open centre of this agency or none. */
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        $centreId = ! empty($d['centre_id']) ? (int) $d['centre_id'] : null;
        if ($centreId === null && $scope !== null && count($scope) === 1) {
            $centreId = (int) $scope[0];
        }
        if ($centreId !== null && ! in_array($centreId, \App\Support\Live::centreIds($agencyId), true)) {
            return [$agencyId, [], null, response()->json(['message' => 'That centre is not open in this agency.'], 422)];
        }
        if (! \App\Support\CentreScope::canWrite($scope, $centreId)) {
            return [$agencyId, [], null, response()->json(['message' => $centreId === null ? 'Choose one of your centres for this increase.' : 'You can only schedule increases for your own centres.'], $centreId === null ? 422 : 403)];
        }
        return [$agencyId, $d, $centreId, null];
    }

    // ===================================
    // Pro-rated tuition
    // ===================================
    public function proratePreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => 'required|integer',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'base_fee' => 'required|numeric|min:0',
        ]);
        $child = DB::table('children')->where('id', $data['child_id'])->first();
        abort_unless($child, 404);
        /* The child must be at a centre you may see, in this agency (2026-11-05 leak sweep): child_id was read
           unchecked, so the preview disclosed any agency's child's enrolment date by id. */
        $agencyId = $this->resolveAgencyId($request);
        $childCentre = (int) DB::table('families')->where('id', (int) $child->family_id)->value('centre_id');
        abort_unless($childCentre
            && (int) DB::table('centres')->where('id', $childCentre)->value('agency_id') === $agencyId
            && \App\Support\CentreScope::canRead(\App\Support\CentreScope::ids($request, $agencyId), $childCentre), 403);
        $start = Carbon::parse($data['period_start']);
        $end = Carbon::parse($data['period_end']);
        $totalDays = Carbon::parse($end->copy()->startOfMonth())->daysInMonth;
        $attendStart = Carbon::parse($child->enrolled_at ?? $start)->max($start);
        $attendEnd = $end;
        $daysAttended = max(0, (int) $attendStart->diffInDays($attendEnd) + 1);
        $base = (float) $data['base_fee'];
        $prorated = round(($daysAttended / $totalDays) * $base, 2);
        return response()->json([
            'base_fee' => $base,
            'days_attended' => $daysAttended,
            'total_days' => $totalDays,
            'prorated_fee' => $prorated,
            'savings' => round($base - $prorated, 2),
        ]);
    }

    /* Agency admin / platform admin of the family's agency (the active one), or the centre director of the
       family's own centre (2026-11-05 leak sweep). */
    private function mayDecideForFamily(Request $request, int $familyId): bool
    {
        $centreId = (int) DB::table('families')->where('id', $familyId)->value('centre_id');
        if (! $centreId) return false;
        $agencyId = $this->resolveAgencyId($request);
        if ((int) DB::table('centres')->where('id', $centreId)->value('agency_id') !== $agencyId) return false;
        if (\App\Support\CentreScope::ids($request, $agencyId) === null) return true;
        return DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
            ->where('role', 'centre_director')->where('centre_id', $centreId)->exists();
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
