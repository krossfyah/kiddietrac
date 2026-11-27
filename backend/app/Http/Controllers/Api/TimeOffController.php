<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p51 — Time-off request workflow.
 * Staff submit; centre director / agency_admin approve / decline.
 * Approval auto-creates a "blocked" calendar marker so shifts can't be
 * scheduled over approved time off (handled in SchedulingController).
 */
final class TimeOffController extends Controller
{
    public function mine(Request $request): JsonResponse
    {
        $rows = DB::table('time_off_requests')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('start_at')
            ->get();
        $this->withLeave($rows, true);
        return response()->json(['data' => $rows]);
    }

    /**
     * KIDDIETRAC HR (2026-11-27): the leave type and the balance on every request, for agencies with the HR add-on.
     * Without it nothing is added and the screen is exactly as before. $staffView hides the balance of a type the
     * agency does not show to staff (the request itself still shows).
     */
    private function withLeave($rows, bool $staffView = false): void
    {
        $on = [];
        $pendAll = [];
        foreach ($rows as $r) {
            $aid = (int) $r->agency_id;
            if (! isset($on[$aid])) {
                $on[$aid] = \App\Support\HrLeave::on($aid);
            }
            if (! $on[$aid]) {
                continue;
            }
            $t = \App\Support\HrLeave::typeForRequest($r);
            if (! $t) {
                continue;
            }
            $amount = \App\Support\HrLeave::amountFor($r, $t);
            $r->leave_type_name = $t->name;
            $r->leave_colour = $t->colour;
            $r->leave_unit = $t->unit;
            $r->leave_amount = $amount;
            if ($staffView && ! $t->visible_to_staff) {
                continue;
            }
            $bal = \App\Support\HrLeave::balance((int) $r->user_id, (int) $t->id);
            $r->leave_balance = $bal;
            // What is left once this request counts: pending = not deducted yet; approved = already deducted.
            $r->leave_balance_after = $r->status === 'pending' ? round($bal - $amount, 2) : $bal;
            /* HR4B9 (2026-11-27): what is left after EVERY pending request of this type for this person (this one included),
               so an approver sees the balance the way it will end up, not one request at a time. */
            if ($r->status === 'pending') {
                $pk = (int) $r->user_id . ':' . (int) $t->id;
                $pendAll[$pk] ??= \App\Support\HrLeave::pending($aid, (int) $r->user_id, $t);
                $r->leave_pending_all = $pendAll[$pk];
                $r->leave_balance_projected = round($bal - $pendAll[$pk], 2);
            }
        }
    }

    /** Would approving this take the balance below zero where the agency does not allow it? The refusal, or null. */
    private function negativeRefusal(object $row): ?string
    {
        $aid = (int) $row->agency_id;
        if (! \App\Support\HrLeave::on($aid) || ($t = \App\Support\HrLeave::typeForRequest($row)) === null) {
            return null;
        }
        if ($t->accrual === 'none' && ! DB::table('hr_leave_ledger')->where('user_id', $row->user_id)->where('leave_type_id', $t->id)->exists()) {
            return null;   // an untracked type (e.g. unpaid leave) has no balance to go below
        }
        $after = \App\Support\HrLeave::balance((int) $row->user_id, (int) $t->id) - \App\Support\HrLeave::amountFor($row, $t);
        if ($after >= -0.005 || \App\Support\HrLeave::settings($aid)['allow_negative']) {
            return null;
        }

        return 'This would take the ' . $t->name . ' balance below zero (' . rtrim(rtrim(number_format($after, 2), '0'), '.') . ' ' . $t->unit
            . '). Your agency does not allow negative balances: adjust the balance first, or decline.';
    }

    public function listAgency(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $status = $request->query('status');
        /* A DIRECTOR SEES THEIR OWN CENTRES' REQUESTS (2026-11-27, with HR balances on every row). The list was bounded
           by the agency only, so a director saw (and now would have seen the balances of) another centre's staff.
           Same rule as who may decide one (assertDecider): the request's centre, or a centre the requester works at. */
        $scope = \App\Support\CentreScope::ids($request, (int) $agencyId);
        /* HR4B6 (2026-11-27): + the people and centres an approver grant or a cover gives this person (HrApprovers);
           those rows are then kept only when via() allows them, and say why they are here. */
        $extra = $scope !== null ? \App\Support\HrApprovers::listExtras((int) $agencyId, (int) $request->user()->id) : null;
        $wide = $extra ? array_values(array_unique(array_merge($scope, $extra['centres']))) : $scope;
        $q = DB::table('time_off_requests as tor')
            ->join('users as u', 'u.id', '=', 'tor.user_id')
            ->where('tor.agency_id', $agencyId)
            ->when($scope !== null, function ($w) use ($wide, $extra) {
                $w->where(function ($s) use ($wide, $extra) {
                    $s->whereIn('tor.centre_id', $wide ?: [0])
                      ->orWhere(fn ($n) => $n->whereNull('tor.centre_id')->whereExists(fn ($e) => $e->from('role_assignments as sra')
                          ->whereColumn('sra.user_id', 'tor.user_id')->where('sra.active', 1)
                          ->whereIn('sra.role', \App\Support\Visibility::STAFF_ROLES)->whereIn('sra.centre_id', $wide ?: [0])->select(DB::raw(1))))
                      ->orWhere(fn ($n) => $n->whereNull('tor.centre_id')->whereExists(fn ($e) => $e->from('educator_rooms as er')->join('rooms as rm', 'rm.id', '=', 'er.room_id')
                          ->whereColumn('er.user_id', 'tor.user_id')->whereIn('rm.centre_id', $wide ?: [0])->select(DB::raw(1))));
                    if ($extra && $extra['users']) $s->orWhereIn('tor.user_id', $extra['users']);
                });
            })
            ->orderByDesc('tor.start_at')
            ->select(
                'tor.*', DB::raw("CONCAT(u.first_name, ' ', u.last_name) as user_name"), 'u.email as user_email',
                // decided_by_id and decided_at have always been written and never read
                // back — the id was returned raw by tor.* and no screen could turn it
                // into a person. The decision is half the record: who allowed it matters
                // as much as that it was allowed.
                DB::raw("TRIM(CONCAT(COALESCE(d.first_name,''), ' ', COALESCE(d.last_name,''))) as decided_by_name")
            )
            ->leftJoin('users as d', 'd.id', '=', 'tor.decided_by_id');
        if ($status) $q->where('tor.status', $status);
        $rows = $q->get();
        if ($extra) {   // HR4B6: a row reached only through a grant stays only if the grant really covers it
            $me = (int) $request->user()->id;
            $rows = $rows->filter(function ($r) use ($scope, $me) {
                if (array_intersect(\App\Support\HrApprovers::requestCentres($r), $scope)) return true;
                $via = \App\Support\HrApprovers::via($me, $r);
                if (! $via) return false;
                $r->via_kind = $via['kind'];
                $r->via_label = \App\Support\HrApprovers::label($via);
                $r->via_can_close = (bool) $via['close'];

                return true;
            })->values();
        }
        // Which centre each request would close, and whether it already is closed for those
        // dates — so the screen can offer "Mark closed" on an approved request (2026-10-13).
        foreach ($rows as $r) {
            $cid = $this->centreFor($r);
            $r->close_centre_id = $cid;
            $r->close_centre_name = $cid ? DB::table('centres')->where('id', $cid)->value('name') : null;
            $r->closed_for_dates = $cid ? $this->closureFor($cid, $r) !== null : false;
        }
        $this->withLeave($rows);
        /* KIDDIETRAC HR PHASE 4 (2026-11-27, App\Support\HrAttendance): on a request still waiting, how many others at the
           centre are off or have asked, and whether approving would go below the centre's minimum staff. */
        if ($agencyId && \App\Support\HrLeave::on((int) $agencyId)) {
            foreach ($rows as $r) {
                if (($r->status ?? '') !== 'pending') continue;
                try {
                    $c = \App\Support\HrAttendance::forRequest($r, false);
                    $r->clash_others = $c['warn'] ? (int) $c['others'] : 0;
                    $r->clash_short = (bool) $c['short'];
                } catch (\Throwable $e) {
                    report($e);
                }
            }
            /* HR4B9 (2026-11-27): a closure changed after this was booked, so its amount may be wrong. Nothing was changed;
               the approver reviews it (Time off > Closures & checks, or from the row). */
            try {
                if (\App\Support\HrLeaveMaths::ready() && $rows->count()) {
                    $flags = DB::table('hr_leave_rechecks')->where('agency_id', $agencyId)->where('status', 'open')
                        ->whereIn('time_off_request_id', $rows->pluck('id')->all())->get(['id', 'time_off_request_id', 'booked', 'fresh', 'unit', 'cause'])->keyBy('time_off_request_id');
                    foreach ($rows as $r) {
                        if ($f = $flags[$r->id] ?? null) {
                            $r->leave_recheck = ['id' => (int) $f->id, 'booked' => (float) $f->booked, 'fresh' => (float) $f->fresh, 'unit' => $f->unit, 'cause' => $f->cause];
                        }
                    }
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
        return response()->json(['data' => $rows]);
    }

    /**
     * The centre a request belongs to: its own, or the one centre the person works at.
     * Somebody at several centres has no single centre to close, so null.
     */
    private function centreFor(object $row): ?int
    {
        /* Only a centre of the request's own agency (2026-11-05 leak sweep): centre_id was stored as submitted, so
           a request naming another agency's centre made approval close that centre and email its families. The
           fallback likewise counted the person's centres in every agency. */
        $inAgency = \App\Support\AgencyRole::centreIds((int) $row->agency_id);
        $centreId = (int) ($row->centre_id ?? 0);
        if ($centreId) {
            return in_array($centreId, $inAgency, true) ? $centreId : null;
        }
        $centreIds = DB::table('role_assignments')->whereIn('role', \App\Support\Visibility::STAFF_ROLES)
            ->where('user_id', $row->user_id)->where('active', true)
            ->whereIn('centre_id', $inAgency ?: [0])->distinct()->pluck('centre_id');

        return $centreIds->count() === 1 ? (int) $centreIds->first() : null;
    }

    /** A closure already covering any of the request's dates at this centre, if there is one. */
    private function closureFor(int $centreId, object $row): ?object
    {
        return DB::table('centre_closures')
            ->where('centre_id', $centreId)
            ->whereDate('closure_date', '<=', $row->end_at)
            // A null end_date is ONE day (App\Support\Closures), not an open range: two
            // old single-day closures made a centre look closed on every later date.
            ->where(function ($q) use ($row) {
                $q->where(fn ($one) => $one->whereNull('end_date')->whereDate('closure_date', '>=', $row->start_at))
                  ->orWhere(fn ($rng) => $rng->whereNotNull('end_date')->whereDate('end_date', '>=', $row->start_at));
            })
            ->first();
    }

    /** Families at a centre who should hear about a closure: open families with a child still here. */
    private function closureParentIds(int $centreId)
    {
        $familyIds = \App\Support\Live::families(DB::table('families as f'), 'f')
            ->where('f.centre_id', $centreId)
            ->whereExists(fn ($q) => \App\Support\Live::children($q->from('children as ch'), 'ch')
                ->whereColumn('ch.family_id', 'f.id')->select(DB::raw(1)))
            ->pluck('f.id');

        return DB::table('users as u')->join('guardians as g', 'g.user_id', '=', 'u.id')
            ->whereIn('g.family_id', $familyIds)->whereNull('u.deleted_at')
            ->where(fn ($w) => $w->whereNull('u.status')->orWhereNotIn('u.status', ['suspended', 'deactivated']))
            ->distinct()->pluck('u.id');
    }

    /**
     * GET /admin/time-off/{id}/closure-options — what closing for this request would mean,
     * for the approval dialog: which centre, who else works there, how many parents hear.
     */
    public function closureOptions(Request $request, int $id): JsonResponse
    {
        $row = DB::table('time_off_requests')->where('id', $id)->first();
        abort_unless($row, 404);
        $this->assertAgencyAccess($request, (int) $row->agency_id);
        $this->assertDecider($request, $row);
        $cid = $this->centreFor($row);
        $staff = $cid ? DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.centre_id', $cid)->where('ra.active', true)
            ->whereIn('ra.role', ['educator', 'centre_director'])->whereNull('u.deleted_at')
            ->where('u.id', '!=', $row->user_id)
            ->distinct()->selectRaw("u.id, TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as name")
            ->get() : collect();

        return response()->json([
            'centre_id' => $cid,
            'centre_name' => $cid ? DB::table('centres')->where('id', $cid)->value('name') : null,
            'other_staff' => $staff->pluck('name')->values(),
            'sole_staff' => $cid && $staff->isEmpty(),
            'parents' => $cid ? $this->closureParentIds($cid)->count() : 0,
            'already_closed' => $cid ? $this->closureFor($cid, $row) !== null : false,
            'all_day' => (bool) $row->all_day,
            'when' => self::whenPhrase($row),
        ]);
    }

    /**
     * POST /admin/time-off/{id}/close — close the centre for an already-approved request.
     *
     * Anthony, 2026-10-13: "when approving time off requests for educators add option to
     * flag this educator/centre etc closed which will trigger emails out to parents,
     * admins/directors". Amna's leave was approved without a closure because Safia also
     * holds an educator role at her centre, so the sole-staff rule said "someone covers".
     * This is the same closure and the same parent / admin emails, chosen by a person.
     */
    public function closeForRequest(Request $request, int $id): JsonResponse
    {
        $row = DB::table('time_off_requests')->where('id', $id)->first();
        abort_unless($row, 404);
        $this->assertAgencyAccess($request, (int) $row->agency_id);
        abort_unless($row->status === 'approved', 422, 'Only an approved request can close the centre.');
        $d = $request->validate(['centre_id' => 'nullable|integer']);
        $centre = $this->checkedCentre($row, $d['centre_id'] ?? null);
        abort_unless($centre, 422, 'This person is not at a single centre, so choose the centre to close.');
        $this->assertDecider($request, $row, $centre);
        if ($this->closureFor($centre, $row)) {
            return response()->json(['message' => 'The centre is already closed for these dates.'], 422);
        }
        $closed = $this->closeCentreIfSoleStaff($row, (int) $request->user()->id, 'centre', $centre);
        abort_unless($closed, 500, 'The closure could not be saved.');
        $this->announceApproval($row, $closed, $request->user(), 'closure');

        return response()->json(['closure_created' => true, 'closure_centre_id' => $closed,
            'parents' => $this->closureParentIds($closed)->count()]);
    }

    /** A centre id from the client, accepted only if it is an open centre of the request's agency. */
    private function checkedCentre(object $row, $centreId): ?int
    {
        $cid = $centreId ? (int) $centreId : $this->centreFor($row);
        if (! $cid) {
            return null;
        }
        $ok = \App\Support\Live::centres(DB::table('centres'), 'centres')
            ->where('id', $cid)->where('agency_id', $row->agency_id)->exists();

        return $ok ? $cid : null;
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            // KiddieTrac HR (2026-11-27): an agency's own leave type, or the six original types as before.
            'leave_type_id' => 'nullable|integer',
            'request_type'  => 'required_without:leave_type_id|nullable|string|in:vacation,sick,personal,bereavement,jury,other',
            'start_at'      => 'required|date',
            'end_at'        => 'required|date|after_or_equal:start_at',
            'reason'        => 'nullable|string|max:2000',
            'centre_id'     => 'nullable|integer',
            // Part of a day, for the appointments that do not need a whole one.
            'all_day'       => 'nullable|boolean',
            'start_time'    => 'nullable|date_format:H:i',
            'end_time'      => 'nullable|date_format:H:i|after:start_time',
        ]);

        // Part of a day only makes sense within ONE day. Asking for 2pm–4pm across a
        // fortnight has no meaning anyone could act on.
        $allDay = ! array_key_exists('all_day', $data) ? true : (bool) $data['all_day'];
        if (! $allDay) {
            if (substr((string) $data['start_at'], 0, 10) !== substr((string) $data['end_at'], 0, 10)) {
                return response()->json([
                    'message' => 'Part of a day has to be a single date. Use all day for a range.',
                ], 422);
            }
            if (empty($data['start_time']) || empty($data['end_time'])) {
                return response()->json([
                    'message' => 'Give a start and end time, or ask for the whole day.',
                ], 422);
            }
        }
        $user = $request->user();
        /* STAFF ONLY (2026-11-06 role sweep): a parent could post here and land in the staff approval queue. */
        if (! array_intersect(\App\Support\AgencyRole::names($request), array_merge(\App\Support\Visibility::STAFF_ROLES, ['platform_admin']))) {
            return response()->json(['message' => 'Time off is for staff. To tell the centre your child will be away, use Absences or Vacation holds.'], 403);
        }
        $agencyId = $this->resolveAgencyId($request);

        /* The centre must be one you work at (2026-11-05 leak sweep): centre_id was written unchecked, so a
           request could name any centre, and approving it closed that centre and told its families. */
        $agencyWide = DB::table('role_assignments')->where('user_id', $user->id)->where('active', true)
            ->whereIn('role', \App\Support\Visibility::STAFF_ROLES)->whereNull('centre_id')->where('agency_id', $agencyId)->exists();
        if (! empty($data['centre_id'])
            && ! in_array((int) $data['centre_id'], $agencyWide ? \App\Support\AgencyRole::centreIds((int) $agencyId)
                : \App\Support\Visibility::centreIds($agencyId, $user), true)) {
            return response()->json(['message' => 'Choose a centre you work at.'], 422);
        }

        /* KIDDIETRAC HR (2026-11-27): the request names one of the agency's leave types. The original request_type is
           still written (the type's mapping, else "other") so closures, emails and the calendar read it as before. */
        $leaveType = null;
        $leaveAmount = null;
        $warning = null;
        if (! empty($data['leave_type_id'])) {
            abort_unless(\App\Support\HrLeave::on((int) $agencyId), 422, 'Choose a type of time off.');
            $leaveType = DB::table('hr_leave_types')->where('id', (int) $data['leave_type_id'])->where('agency_id', $agencyId)
                ->whereNull('archived_at')->first();
            if (! $leaveType || (! $leaveType->visible_to_staff && ! array_intersect(\App\Support\AgencyRole::names($request), ['agency_admin', 'centre_director', 'platform_admin']))) {
                return response()->json(['message' => 'Choose a type of time off.'], 422);
            }
            $data['request_type'] = $leaveType->maps_request_type ?: 'other';
            $probe = (object) ['agency_id' => $agencyId, 'user_id' => $user->id, 'leave_type_id' => $leaveType->id, 'leave_amount' => null,
                'request_type' => $data['request_type'], 'start_at' => $data['start_at'], 'end_at' => $data['end_at'], 'all_day' => $allDay,
                'start_time' => $allDay ? null : $data['start_time'], 'end_time' => $allDay ? null : $data['end_time'], 'status' => 'pending'];
            $leaveAmount = \App\Support\HrLeave::amountFor($probe, $leaveType);
            $tracked = $leaveType->accrual !== 'none' || DB::table('hr_leave_ledger')->where('user_id', $user->id)->where('leave_type_id', $leaveType->id)->exists();
            if ($tracked) {
                $after = \App\Support\HrLeave::balance((int) $user->id, (int) $leaveType->id)
                    - \App\Support\HrLeave::pending((int) $agencyId, (int) $user->id, $leaveType) - $leaveAmount;
                if ($after < -0.005) {
                    $fmt = rtrim(rtrim(number_format($after, 2), '0'), '.') . ' ' . $leaveType->unit;
                    if (! \App\Support\HrLeave::settings((int) $agencyId)['allow_negative']) {
                        return response()->json(['message' => 'You do not have enough ' . $leaveType->name . ' left for this request (it would leave ' . $fmt . ').'], 422);
                    }
                    $warning = 'This takes your ' . $leaveType->name . ' balance below zero (' . $fmt . ').';
                }
            }
        }

        $id = DB::table('time_off_requests')->insertGetId([
            'agency_id'    => $agencyId,
            'user_id'      => $user->id,
            'centre_id'    => $data['centre_id'] ?? null,
            'request_type' => $data['request_type'],
        ] + ($leaveType ? ['leave_type_id' => (int) $leaveType->id, 'leave_amount' => $leaveAmount] : []) + [
            'start_at'     => $data['start_at'],
            'end_at'       => $data['end_at'],
            'reason'       => $data['reason'] ?? null,
            'all_day'      => $allDay,
            'start_time'   => $allDay ? null : $data['start_time'],
            'end_time'     => $allDay ? null : $data['end_time'],
            'status'       => 'pending',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        /* A LEAVE TYPE THAT NEEDS NO APPROVAL (KiddieTrac HR; Anthony 2026-11-26): recorded and taken off the balance
           straight away, through the same applyDecision as a director's approval (balance, closure rule, the rota), and
           flagged TO REVIEW. The managers get the usual notice (bell + email, without approve/deny buttons) and clear the
           flag with Reviewed, which changes nothing in the balance. */
        $autoApproved = false;
        if ($leaveType && ! $leaveType->needs_approval) {
            $row = DB::table('time_off_requests')->where('id', $id)->first();
            $this->applyDecision($row, 'approved', null, $user, 'auto', null);
            if (\Illuminate\Support\Facades\Schema::hasColumn('time_off_requests', 'review_pending')) {
                DB::table('time_off_requests')->where('id', $id)->update(['review_pending' => 1]);
            }
            $autoApproved = true;
        }

        // Centre directors and agency admins are the people who can act on this, so
        // they get both a bell and an email. A bell alone waits for them to open the
        // app, and the request waits with it.
        /* ONLY THE PEOPLE WHO CAN DECIDE IT (2026-10-06). This notified every centre
           director in the agency, so a director at one centre was told about — and emailed
           the details of — another centre's staff leave. A director acts only on their own
           centre (Visibility/CentreScope), so: the agency admins, plus the director(s) of
           the requester's centre — the same rule the approval reminders use
           (TimeOffReminders::approvers: own centre, never yourself, admins as the fallback
           when a centre has no director). */
        $req = (object) ['id' => $id, 'user_id' => (int) $user->id, 'centre_id' => $data['centre_id'] ?? null];
        $req->centre_ids = $req->centre_id ? [(int) $req->centre_id]
            : DB::table('role_assignments')->whereIn('role', \App\Support\Visibility::STAFF_ROLES)->where('user_id', $user->id)->where('agency_id', $agencyId)
                ->where('active', true)->whereNotNull('centre_id')->pluck('centre_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $approvers = collect(\App\Support\TimeOffReminders::approvers($agencyId, ['recipients' => 'both'], collect([$req])))
            ->map(fn ($o) => (object) ['id' => (int) $o['user']->user_id, 'email' => $o['user']->email,
                'first_name' => $o['user']->first_name, 'last_name' => $o['user']->last_name])
            ->values();

        // $user->name is not a thing on this model — no `name` column and no accessor for
        // it, only getFullNameAttribute. It used to be interpolated here and produced
        // "New time-off request from " with nothing after it.
        $who = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'A team member';
        $fresh = DB::table('time_off_requests')->where('id', $id)->first();
        $when = self::whenPhrase($fresh);
        // The year belongs in anything someone reads weeks later — a request for
        // "Sep 3" in an inbox in December is genuinely ambiguous.
        $whenFull = $when . ', ' . Carbon::parse($data['start_at'])->format('Y');

        $typeLabel = $leaveType ? $leaveType->name : (string) $data['request_type'];
        foreach ($approvers as $ap) {
            \App\Support\Notify::write([
                'user_id'    => $ap->id,
                'type'       => 'time_off',
                'title'      => $autoApproved ? $who . ' booked ' . $typeLabel . ': to review' : 'New time-off request from ' . $who,
                'body'       => ($autoApproved ? 'No approval needed, already taken off the balance' : $data['request_type']) . ' · ' . $when,
                'data' => json_encode(['link' => '#time-off']),
                'created_at' => now(),
            ]);

            $this->mailApprover($agencyId, $ap, $who, $typeLabel, $whenFull,
                $data['reason'] ?? null, (int) $id, $autoApproved);
        }

        return response()->json(['id' => $id, 'status' => $autoApproved ? 'approved' : 'pending', 'warning' => $warning, 'review' => $autoApproved], 201);
    }

    /**
     * Close the centre for approved leave, but only when the person IS the centre.
     *
     * A home provider working alone has no cover — their time off shuts the doors, and
     * every family needs to know well in advance. An educator at a staffed centre does
     * not: somebody covers the room and nothing changes for parents. Emailing all of
     * them "we are closed" because one of nine educators booked a holiday is far worse
     * than sending nothing, so the test is deliberately strict — sole ACTIVE staff at
     * that centre, and nobody else.
     *
     * Writes a centre_closures row, which is what ClosureReminderCommand reads; the
     * lead times and the on/off switch stay under the agency's own closure settings, so
     * this changes what gets announced, not how.
     *
     * @return int|null the centre closed, or null if nothing was closed
     */
    private function closeCentreIfSoleStaff($row, int $actorId, string $mode = 'auto', ?int $centreOverride = null): ?int
    {
        /* THE APPROVER CAN DECIDE (2026-10-13). 'auto' is the rule below (all-day leave by
           the only staff member closes the centre). 'centre' closes it because the approver
           said so — the rule cannot see that an admin who holds an educator role at a home
           daycare is not actually there. 'none' never closes. */
        if ($mode === 'none') {
            return null;
        }
        $forced = $mode === 'centre';

        // Away for part of a day is not a closure. The provider is still there for the
        // rest of it, and telling every family the centre is shut because of a two-hour
        // appointment is worse than telling them nothing.
        if (! $forced && empty($row->all_day)) {
            return null;
        }

        // The request's own centre if it carries one; otherwise the single centre this
        // person is staff at. Somebody attached to several centres is by definition not
        // the sole staff member of one, so there is nothing to close.
        $centreId = $centreOverride ?: $this->centreFor($row);
        if (! $centreId) {
            return null;
        }

        // Sole staff, or someone covers.
        $staffCount = DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.centre_id', $centreId)->where('ra.active', true)
            ->whereIn('ra.role', ['educator', 'centre_director'])
            ->whereNull('u.deleted_at')
            ->distinct()->count('ra.user_id');
        if (! $forced && $staffCount > 1) {
            return null;
        }

        // Already closed for these dates — someone entered it by hand, which is exactly
        // what people have been doing. Do not stack a second closure on top.
        $exists = DB::table('centre_closures')
            ->where('centre_id', $centreId)
            ->whereDate('closure_date', '<=', $row->end_at)
            // A null end_date is ONE day (App\Support\Closures), not an open range: two
            // old single-day closures made a centre look closed on every later date.
            ->where(function ($q) use ($row) {
                $q->where(fn ($one) => $one->whereNull('end_date')->whereDate('closure_date', '>=', $row->start_at))
                  ->orWhere(fn ($rng) => $rng->whereNotNull('end_date')->whereDate('end_date', '>=', $row->start_at));
            })
            ->exists();
        if ($exists) {
            return null;
        }

        $who = DB::table('users')->where('id', $row->user_id)
            ->selectRaw("TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) as n")
            ->value('n');

        try {
            DB::table('centre_closures')->insert([
                'centre_id' => $centreId,
                'closure_date' => $row->start_at,
                'end_date' => $row->end_at,
                'closure_type' => 'staff_leave',
                'reason' => trim(($who ?: 'The provider').' away'
                    .(($row->request_type ?? '') ? ' — '.str_replace('_', ' ', (string) $row->request_type) : '')),
                // Whether a closed day is billable is an agency policy decision, not
                // something to assume from a leave request. Left off; it is editable
                // on the closure like any other.
                'affects_billing' => 0,
                'created_by_id' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // A closure that fails to write must not fail the approval — the leave was
            // still granted, and the person is still off.
            \Illuminate\Support\Facades\Log::warning('Time-off closure not created', [
                'request' => $row->id, 'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $centreId;
    }

    /**
     * How a request reads to a human: "3–5 Sep", or "21 Aug, 2:00 PM – 4:00 PM".
     *
     * One place, because the request email, the decision email, the withdrawal email and
     * the calendar all have to say the same thing. They said it three different ways
     * before the times existed and would have said it four afterwards.
     */
    public static function whenPhrase($row): string
    {
        $start = Carbon::parse($row->start_at);
        $end = Carbon::parse($row->end_at);
        $sameDay = $start->toDateString() === $end->toDateString();

        if (! empty($row->all_day) || empty($row->start_time)) {
            return $sameDay ? $start->format('M j') : $start->format('M j').' – '.$end->format('M j');
        }

        $t = fn ($v) => Carbon::parse(substr((string) $v, 0, 8))->format('g:i A');

        return $start->format('M j').', '.$t($row->start_time).' – '.$t($row->end_time);
    }

    /** POST /admin/time-off/{id}/reviewed — clear the "To review" flag on leave that needed no approval. Changes nothing
     *  in the balance. Same deciders as an approval (own centre for a director). */
    public function markReviewed(Request $request, int $id): JsonResponse
    {
        $row = DB::table('time_off_requests')->where('id', $id)->first();
        abort_unless($row, 404, 'That request was not found.');
        $this->assertAgencyAccess($request, (int) $row->agency_id);
        $this->assertDecider($request, $row);
        abort_if((int) $row->user_id === (int) $request->user()->id, 403, 'Someone else reviews your own time off.');
        if (! \Illuminate\Support\Facades\Schema::hasColumn('time_off_requests', 'review_pending') || ! (int) ($row->review_pending ?? 0)) {
            return response()->json(['ok' => true, 'already' => true]);
        }
        DB::table('time_off_requests')->where('id', $id)->update(['review_pending' => 0, 'reviewed_by_id' => (int) $request->user()->id, 'reviewed_at' => now()]);
        \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => (int) $row->agency_id, 'action' => 'time_off.reviewed',
            'entity_type' => 'time_off_request', 'entity_id' => $id, 'payload' => json_encode(['user_id' => (int) $row->user_id])]);

        return response()->json(['ok' => true]);
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|string|in:approved,denied',
            'decision_notes' => 'nullable|string|max:1000',
            // auto = the sole-staff rule; centre = close it; none = someone covers.
            'close' => 'nullable|string|in:auto,centre,none',
            'close_centre_id' => 'nullable|integer',
        ]);
        $row = DB::table('time_off_requests')->where('id', $id)->first();
        abort_unless($row, 404);
        $this->assertAgencyAccess($request, (int) $row->agency_id);
        /* NOBODY DECIDES THEIR OWN TIME OFF (2026-11-27, HR Phase 4). A director or admin who asks for leave saw their own
           request in Team requests with Approve / Deny, and the server accepted it. Someone else decides; the person can
           still withdraw it from their own list (cancel). Same rule as markReviewed and the email buttons below. */
        if ((int) $row->user_id === (int) $request->user()->id) {
            return response()->json(['message' => 'Someone else approves or declines your own time off. You can withdraw it from Your requests.'], 403);
        }
        $mode = $data['close'] ?? 'auto';
        $centre = $mode === 'centre' ? $this->checkedCentre($row, $data['close_centre_id'] ?? null) : null;
        abort_if($mode === 'centre' && ! $centre, 422, 'Choose the centre to close.');
        $via = $this->assertDecider($request, $row, $centre ?: ($mode === 'auto' ? $this->centreFor($row) : null), $mode === 'auto');
        if ($via && ! empty($via['no_close'])) $mode = 'none';   // HR4B6: a grant never closes a centre its holder may not close
        if ($data['status'] === 'approved' && $row->status !== 'approved' && ($no = $this->negativeRefusal($row))) {
            return response()->json(['message' => $no], 422);
        }
        // HR minimum staff, when the agency set it to block (App\Support\HrAttendance; warn = the popup only says so).
        if ($data['status'] === 'approved' && $row->status === 'pending' && ($no = \App\Support\HrAttendance::minStaffRefusal($row))) {
            return response()->json(['message' => $no, 'min_staff' => true], 422);
        }

        $result = $this->applyDecision($row, $data['status'], $data['decision_notes'] ?? null, $request->user(), $mode, $centre);
        if ($via) $this->noteGrantDecision($row, $via, $data['status'], (int) $request->user()->id);

        return response()->json($result);
    }

    /**
     * Record a decision and do everything that follows from it.
     *
     * Extracted so the one-tap buttons in the approver's email run exactly the same
     * path as the portal — the closure, the bell, the emails. Two code paths that both
     * "approve time off" is how they drift until one of them quietly stops closing the
     * centre.
     */
    private function applyDecision(object $row, string $status, ?string $notes, $actor, string $closeMode = 'auto', ?int $closeCentre = null): array
    {
        DB::table('time_off_requests')->where('id', $row->id)->update([
            'status'        => $status,
            'decided_by_id' => $actor->id,
            'decided_at'    => now(),
            'decision_notes'=> $notes,
            'updated_at'    => now(),
        ]);
        // KiddieTrac HR: approving deducts from the balance; a decision that undoes an approval gives it back.
        \App\Support\HrLeave::syncRequest(DB::table('time_off_requests')->where('id', $row->id)->first(), (int) $actor->id);

        $data = ['status' => $status, 'decision_notes' => $notes];
        $when = self::whenPhrase($row);

        \App\Support\Notify::write([
            'user_id'    => $row->user_id,
            'type'       => 'time_off',
            'title'      => 'Your time-off request was ' . $data['status'],
            'body'       => $when . (! empty($data['decision_notes']) ? ' · ' . $data['decision_notes'] : ''),
            'data' => json_encode(['link' => '#time-off']),
            'created_at' => now(),
        ]);

        // Emailed as well as belled. Somebody who asked for leave is waiting on this to
        // book something, and "check the app occasionally" is not an answer.
        if ((int) $actor->id !== (int) $row->user_id) {   // a type with no approval step: they know already
            $this->mailDecision($row, (string) $status, $when, $notes, $actor);
        }

        // A home provider IS the centre, so approving their leave closes it — and the
        // parents need telling on the usual reminder schedule.
        $closure = null;
        if ($status === 'approved') {
            $closure = $this->closeCentreIfSoleStaff($row, $actor->id, $closeMode, $closeCentre);
            // Told now, not only 5 days before it happens. The scheduled reminders still
            // run on top of this — this is the confirmation, those are the nudges.
            $this->announceApproval($row, $closure, $actor);
        }

        return [
            'status' => $status,
            'closure_created' => (bool) $closure,
            'closure_centre_id' => $closure,
        ];
    }

    /**
     * Tell an approver a request is waiting.
     *
     * Never lets a mail failure fail the request itself — the time-off row is already
     * saved and the bell is already written, and a bounced address for one director must
     * not hand the person requesting leave a 500.
     */
    private function mailApprover(?int $agencyId, object $approver, string $who, string $type, string $when, ?string $reason, ?int $requestId = null, bool $review = false): void
    {
        if (! filter_var((string) $approver->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        if (\App\Support\Suppression::isUser((int) $approver->id)) {
            return;
        }

        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $reasonRow = $reason
            ? '<tr><td style="padding:10px 0 0;font-size:14px;line-height:1.6;color:#334155;">'
              . '<strong>Reason given:</strong> ' . $e($reason) . '</td></tr>'
            : '';

        $body = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
            . '<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 12px;">'
            . $e($who) . ($review ? ' has booked time off that needs no approval. It is already recorded and taken off the balance: please review it.' : ' has requested time off and is waiting on a decision.') . '</td></tr>'
            . '<tr><td style="padding:6px 0;"><div style="background:#F1F5F9;border-radius:10px;padding:14px 16px;'
            . 'font-size:15px;color:#0F172A;"><strong>' . $e(ucfirst($type)) . '</strong><br>' . $e($when) . '</div></td></tr>'
            . $reasonRow
            . $this->balanceRow($requestId)
            . ($review ? '' : $this->quickButtons($requestId, (int) $approver->id))
            . '<tr><td style="padding:16px 0 0;font-size:14px;line-height:1.6;color:#64748B;">'
            . ($review ? 'Mark it <strong>Reviewed</strong> under <strong>Time off</strong> in KiddieTrac.' : 'Or handle it under <strong>Time off</strong> in KiddieTrac.') . '</td></tr></table>';

        try {
            $html = EmailTemplate::wrap($agencyId, $body, [
                'eyebrow' => $review ? 'TO REVIEW' : 'ACTION NEEDED',
                'title' => 'Time-off request from ' . $who,
                'subtitle' => ucfirst($type) . ' · ' . $when,
                'preheader' => $who . ($review ? ' booked ' : ' requested ') . $type . ' for ' . $when,
            ]);
            $name = trim(($approver->first_name ?? '') . ' ' . ($approver->last_name ?? ''));
            // The dates go in the SUBJECT. An inbox full of "Time-off request from
            // Natasha" cannot be triaged without opening every one of them.
            $subject = ($review ? 'Time off to review: ' : 'Time off: ') . $who . ' — ' . $when;
            AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($approver, $name, $subject) {
                $m->to($approver->email, $name ?: null)->subject($subject);
            });
        } catch (\Throwable $ex) {
            \Illuminate\Support\Facades\Log::warning('Time-off approver email failed', [
                'approver' => $approver->id, 'error' => $ex->getMessage(),
            ]);
        }
    }

    /** KiddieTrac HR: the balance line under a request in the approver's email (empty without the add-on). */
    private function balanceRow(?int $requestId): string
    {
        $r = $requestId ? DB::table('time_off_requests')->where('id', $requestId)->first() : null;
        [, $line] = $r ? \App\Support\HrLeave::emailLine($r) : [null, null];

        $out = $line ? '<tr><td style="padding:10px 0 0;font-size:14px;line-height:1.6;color:#0E7490;"><strong>Balance:</strong> '
            . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</td></tr>' : '';
        // HR Phase 4: who else at the centre is away, and the minimum-staff check (first names only).
        $clash = $r ? \App\Support\HrAttendance::emailLine($r) : null;

        return $out . ($clash ? '<tr><td style="padding:8px 0 0;font-size:14px;line-height:1.6;color:#B45309;"><strong>Also away:</strong> '
            . htmlspecialchars($clash, ENT_QUOTES, 'UTF-8') . '</td></tr>' : '');
    }

    /** Tell the requester what was decided, and by whom. */
    /**
     * The two buttons. Both open the same page; the one you tapped is only a hint at
     * which way you were leaning, because the decision is made on that page, not by the
     * link. That is what stops a mail scanner from deciding on your behalf.
     */
    private function quickButtons(?int $requestId, int $approverId): string
    {
        if (! $requestId) {
            return '';
        }
        try {
            $mk = fn ($a) => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'timeoff.act', now()->addDays(14), ['id' => $requestId, 'u' => $approverId, 'a' => $a]);
            $approve = $mk('approved');
            $decline = $mk('denied');
        } catch (\Throwable $e) {
            return '';
        }

        $btn = fn ($url, $label, $bg, $fg, $border) =>
            '<a href="' . $url . '" style="display:block;padding:14px 18px;border-radius:12px;'
            . 'background:' . $bg . ';color:' . $fg . ';border:' . $border . ';text-decoration:none;'
            . 'font-size:16px;font-weight:700;text-align:center;">' . $label . '</a>';

        return '<tr><td style="padding:18px 0 0;">'
            . '<div style="font-size:13px;color:#64748B;margin-bottom:10px;">Decide from here:</div>'
            . $btn($approve, 'Approve', '#16A34A', '#ffffff', '0')
            . '<div style="height:10px;line-height:10px;"> </div>'
            . $btn($decline, 'Decline', '#ffffff', '#B91C1C', '1px solid #FCA5A5')
            . '<div style="font-size:12px;color:#94A3B8;margin-top:10px;">'
            . 'You can add a note for them on the next screen.</div>'
            . '</td></tr>';
    }

    /**
     * GET — the page the email button opens. DISPLAYS ONLY.
     *
     * Mail scanners fetch every link in a message before it is delivered, so this must
     * not change anything. It shows the request and offers two buttons that POST.
     */
    public function actPage(Request $request, int $id)
    {
        $row = DB::table('time_off_requests')->where('id', $id)->first();
        $approver = $row ? DB::table('users')->where('id', (int) $request->query('u'))->first() : null;

        if (! $row || ! $approver) {
            return response($this->actShell('That request could not be found.',
                'It may have been removed.'), 404)->header('Content-Type', 'text/html');
        }

        // Re-checked at the moment of use, not only when the mail was sent. Somebody who
        // has since left the agency must not still be able to decide from an old email.
        if (! $this->isApproverFor((int) $approver->id, (int) $row->agency_id)) {
            return response($this->actShell('You cannot action this request.',
                'This link belongs to someone who no longer approves time off for this agency.'), 403)
                ->header('Content-Type', 'text/html');
        }

        if ((int) $approver->id === (int) $row->user_id) {   // nobody decides their own time off (2026-11-27)
            return response($this->actShell('Someone else decides this one.',
                'This is your own request, so another director or an agency admin approves or declines it.'), 403)
                ->header('Content-Type', 'text/html');
        }
        if ($this->emailDecider((int) $approver->id, $row) === false) {   // HR4B6
            return response($this->actShell('You cannot action this request.',
                'You no longer approve time off for this person (a cover may have ended). Someone else will decide it.'), 403)
                ->header('Content-Type', 'text/html');
        }

        if ($row->status !== 'pending') {
            $by = DB::table('users')->where('id', $row->decided_by_id)
                ->selectRaw("TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) as n")->value('n');

            return response($this->actShell('Already '.e($row->status).'.',
                'This was '.e($row->status).($by ? ' by '.e($by) : '').
                ($row->decided_at ? ' on '.Carbon::parse($row->decided_at)->format('j M Y') : '').
                '. Nothing more to do.'), 200)->header('Content-Type', 'text/html');
        }

        $who = DB::table('users')->where('id', $row->user_id)
            ->selectRaw("TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) as n")->value('n') ?: 'A team member';
        $action = $request->query('a') === 'denied' ? 'denied' : 'approved';

        return response($this->actForm($request, $row, $who, $action), 200)
            ->header('Content-Type', 'text/html');
    }

    /** POST — the actual decision, from a button on that page. */
    public function actSubmit(Request $request, int $id)
    {
        $row = DB::table('time_off_requests')->where('id', $id)->first();
        $approver = $row ? DB::table('users')->where('id', (int) $request->query('u')) ->first() : null;
        if (! $row || ! $approver || ! $this->isApproverFor((int) $approver->id, (int) $row->agency_id)) {
            return response($this->actShell('You cannot action this request.', ''), 403)
                ->header('Content-Type', 'text/html');
        }
        if ((int) $approver->id === (int) $row->user_id) {   // nobody decides their own time off (2026-11-27)
            return response($this->actShell('Someone else decides this one.',
                'This is your own request, so another director or an agency admin approves or declines it.'), 403)
                ->header('Content-Type', 'text/html');
        }
        $via = $this->emailDecider((int) $approver->id, $row);   // HR4B6
        if ($via === false) {
            return response($this->actShell('You cannot action this request.',
                'You no longer approve time off for this person (a cover may have ended). Someone else will decide it.'), 403)
                ->header('Content-Type', 'text/html');
        }
        if ($row->status !== 'pending') {
            return response($this->actShell('Already decided.',
                'Someone got to it first — no change made.'), 200)->header('Content-Type', 'text/html');
        }

        $status = $request->input('decision') === 'denied' ? 'denied' : 'approved';
        $notes = trim((string) $request->input('decision_notes', '')) ?: null;
        if ($status === 'approved' && ($no = $this->negativeRefusal($row))) {
            return response($this->actShell('Not approved yet.', e($no)), 422)->header('Content-Type', 'text/html');
        }
        if ($status === 'approved' && ($no = \App\Support\HrAttendance::minStaffRefusal($row))) {
            return response($this->actShell('Not approved yet.', e($no)), 422)->header('Content-Type', 'text/html');
        }
        // The page shows the closure box only when there is one centre to close.
        $mode = $request->has('close_shown') ? ($request->input('close') === '1' ? 'centre' : 'none') : 'auto';
        // HR4B6: through a grant, a centre is closed only when the grant allows closing that centre.
        if (is_array($via) && $mode !== 'none' && ! in_array((int) $this->centreFor($row), $via['close'], true)) $mode = 'none';

        $res = $this->applyDecision($row, $status, $notes, $approver, $mode, $mode === 'centre' ? $this->centreFor($row) : null);
        if (is_array($via)) $this->noteGrantDecision($row, $via, $status, (int) $approver->id);

        $msg = $status === 'approved' ? 'Approved.' : 'Declined.';
        $sub = 'We have let them know'.($notes ? ', along with your note' : '').'.'
            .(! empty($res['closure_created']) ? ' The centre is now marked closed for those dates and parents will be told.' : '');

        return response($this->actShell($msg, $sub, $status === 'approved' ? '#16A34A' : '#B91C1C'), 200)
            ->header('Content-Type', 'text/html');
    }

    private function isApproverFor(int $userId, int $agencyId): bool
    {
        return DB::table('role_assignments')->where('user_id', $userId)
            ->where('agency_id', $agencyId)
            ->whereIn('role', ['centre_director', 'agency_admin', 'platform_admin'])
            ->where('active', true)->exists();
    }

    /** A plain result page. Deliberately tiny — it is read on a phone, once. */
    private function actShell(string $title, string $sub, string $accent = '#1F6080'): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.$e($title).'</title></head>'
            .'<body style="margin:0;font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#F1F5F9;">'
            .'<div style="max-width:460px;margin:0 auto;padding:48px 20px;">'
            .'<div style="background:#fff;border-radius:16px;padding:32px 24px;text-align:center;'
            .'box-shadow:0 2px 12px rgba(15,23,42,.08);">'
            .'<h1 style="margin:0 0 10px;font-size:22px;color:'.$accent.';">'.$e($title).'</h1>'
            .($sub ? '<p style="margin:0;font-size:15px;line-height:1.6;color:#475569;">'.$sub.'</p>' : '')
            .'</div></div></body></html>';
    }

    /** The decision form. */
    /** The "close the centre" choice on the email approval page — ticked when the rule would close it. */
    private function actCloseBox(object $row): string
    {
        $cid = $this->centreFor($row);
        if (! $cid || $this->closureFor($cid, $row)) {
            return '';
        }
        $name = DB::table('centres')->where('id', $cid)->value('name');
        $others = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.centre_id', $cid)->where('ra.active', true)->whereIn('ra.role', ['educator', 'centre_director'])
            ->whereNull('u.deleted_at')->where('u.id', '!=', $row->user_id)->distinct()->count('u.id');
        $tick = $others === 0 && ! empty($row->all_day);
        $parents = $this->closureParentIds($cid)->count();
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        return '<input type="hidden" name="close_shown" value="1">'
            .'<label style="display:flex;gap:10px;align-items:flex-start;background:#FEF3C7;border:1px solid #FCD34D;'
            .'border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:14px;line-height:1.5;color:#78350F;cursor:pointer;">'
            .'<input type="checkbox" name="close" value="1"'.($tick ? ' checked' : '').' style="margin-top:3px;width:18px;height:18px;">'
            .'<span><strong>Close '.$e($name).' for these dates</strong><br>'
            .'Emails '.$parents.' parent'.($parents === 1 ? '' : 's').', admins and directors (only if you approve).</span></label>';
    }

    private function actForm(Request $request, object $row, string $who, string $action): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $when = self::whenPhrase($row).', '.Carbon::parse($row->start_at)->format('Y');
        // Same signature, so the POST is as protected as the GET was.
        $post = $request->fullUrl();

        return '<!doctype html><html><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Time-off request from '.$e($who).'</title></head>'
            .'<body style="margin:0;font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#F1F5F9;">'
            .'<div style="max-width:460px;margin:0 auto;padding:32px 16px;">'
            .'<div style="background:#fff;border-radius:16px;padding:24px;box-shadow:0 2px 12px rgba(15,23,42,.08);">'
            .'<div style="font-size:11.5px;font-weight:800;letter-spacing:.08em;color:#94A3B8;'
            .'text-transform:uppercase;margin-bottom:6px;">Time-off request</div>'
            .'<h1 style="margin:0 0 4px;font-size:21px;color:#0F172A;">'.$e($who).'</h1>'
            .'<div style="font-size:15px;color:#334155;margin-bottom:16px;">'
            .$e(ucfirst(str_replace('_', ' ', (string) $row->request_type))).' &middot; '.$e($when).'</div>'
            .($row->reason
                ? '<div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:12px 14px;'
                  .'font-size:14px;line-height:1.6;color:#334155;margin-bottom:16px;white-space:pre-wrap;">'
                  .'<strong>Reason given:</strong> '.$e($row->reason).'</div>'
                : '')
            .'<form method="POST" action="'.$e($post).'">'
            .'<label style="display:block;font-size:13px;font-weight:700;color:#334155;margin-bottom:5px;">'
            .'Add a note back to '.$e(explode(' ', $who)[0]).' (optional)</label>'
            .'<textarea name="decision_notes" rows="3" placeholder="Anything they should know…" '
            .'style="width:100%;box-sizing:border-box;padding:11px;border:1px solid #CBD5E1;border-radius:10px;'
            .'font-family:inherit;font-size:15px;margin-bottom:16px;"></textarea>'
            .$this->actCloseBox($row)
            .'<button type="submit" name="decision" value="approved" '
            .'style="width:100%;padding:15px;border:0;border-radius:12px;background:#16A34A;color:#fff;'
            .'font-size:16px;font-weight:700;cursor:pointer;margin-bottom:10px;">Approve</button>'
            .'<button type="submit" name="decision" value="denied" '
            .'style="width:100%;padding:15px;border:1px solid #FCA5A5;border-radius:12px;background:#fff;'
            .'color:#B91C1C;font-size:16px;font-weight:700;cursor:pointer;">Decline</button>'
            .'</form>'
            .'<p style="font-size:12px;color:#94A3B8;margin:16px 0 0;text-align:center;">'
            .'This link is personal to you and expires in 14 days.</p>'
            .'</div></div></body></html>';
    }

    /**
     * Everybody who needs to know, told at the moment of approval.
     *
     * Three letters rather than one, because the same fact lands differently: a parent
     * needs to arrange care, an educator needs to know the rota moved, an admin needs
     * the record. One generic email to all three says nothing useful to any of them.
     *
     * Parents hear ONLY when the centre actually closes. Leave taken by one of several
     * educators changes nothing for a family, and telling them it does is alarming and
     * untrue.
     *
     * @param int|null $closedCentreId the centre this closed, or null if it closed none
     */
    private function announceApproval(object $row, ?int $closedCentreId, $actor, ?string $only = null): void
    {
        $when = self::whenPhrase($row).', '.Carbon::parse($row->start_at)->format('Y');
        $who = DB::table('users')->where('id', $row->user_id)
            ->selectRaw("TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) as n")
            ->value('n') ?: 'A team member';
        $centreId = $closedCentreId ?: (int) ($row->centre_id ?? 0);
        $centreName = $centreId ? DB::table('centres')->where('id', $centreId)->value('name') : null;
        $agencyId = (int) $row->agency_id;
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $panel = function (string $tint, string $accent, string $label, string $line) use ($e) {
            return '<tr><td style="padding:6px 0;"><div style="background:'.$tint.';border-radius:10px;'
                .'padding:14px 16px;font-size:15px;color:#0F172A;"><strong style="color:'.$accent.';'
                .'text-transform:uppercase;font-size:12px;letter-spacing:.06em;">'.$e($label).'</strong><br>'
                .'<span style="font-size:15px;">'.$e($line).'</span></div></td></tr>';
        };

        $send = function (array $addresses, string $subject, string $body, array $meta) use ($agencyId, $centreId) {
            $addresses = array_values(array_unique(array_filter($addresses,
                fn ($a) => filter_var((string) $a, FILTER_VALIDATE_EMAIL))));
            if (! $addresses) {
                return;
            }
            try {
                $html = EmailTemplate::wrap($agencyId, $body, $meta);
            } catch (\Throwable $e2) {
                return;
            }
            // One blind copy per batch for the director / agency admin. Deduped, so the
            // admin block below never copies them on their own message.
            $bcc = \App\Support\MailOversight::bccFor($agencyId, $centreId ?: null, $addresses);
            foreach ($addresses as $__i => $addr) {
                $copyTo = \App\Support\MailOversight::firstOnly($bcc, $__i);
                try {
                    \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($addr, $subject, $copyTo) {
                        $m->to($addr)->subject($subject);
                        if ($copyTo) {
                            $m->bcc($copyTo);
                        }
                        // A closure is operational: it must arrive even where an agency
                        // has bulk notifications switched off.
                        $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
                    });
                } catch (\Throwable $ex) {
                    \Illuminate\Support\Facades\Log::warning('Time-off approval email failed', [
                        'to' => $addr, 'error' => $ex->getMessage(),
                    ]);
                }
            }
        };

        // ── PARENTS — only when the doors are actually shut ─────────────────
        if ($closedCentreId) {
            // Families read the home as they know it: "Amna's home child care", not the provider's full name (FamilyFacingName, 2026-11-27).
            $staffCentreName = $centreName;
            $centreName = \App\Support\FamilyFacingName::place((int) $closedCentreId, (string) $centreName) ?: $centreName;
            // Open families with a child still here — never a family that has left.
            $parents = DB::table('users')->whereIn('id', $this->closureParentIds($closedCentreId))->pluck('email')->all();

            $body = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
                .'<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 12px;">'
                .'We wanted to let you know as early as we can that '
                .($centreName ? '<strong>'.$e($centreName).'</strong>' : 'your centre')
                .' will be closed on the date below.</td></tr>'
                .$panel('#FEF3C7', '#B45309', 'Closed', $when)
                // The thing a parent is actually worried about, answered before they have
                // to ask it.
                .'<tr><td style="padding:16px 0 0;font-size:15px;line-height:1.6;color:#334155;">'
                .'Your child’s place with us is completely unaffected. We will see them as usual on '
                .'the next opening day and everything carries on exactly as before.</td></tr>'
                .'<tr><td style="padding:14px 0 0;font-size:15px;line-height:1.6;color:#334155;">'
                .'We know a closure is not easy to plan around, especially at short notice, and we are '
                .'sorry for the disruption to your week. If it leaves you stuck for care, please just '
                .'reply to this email — we will help however we can.</td></tr>'
                .'<tr><td style="padding:16px 0 0;font-size:14px;line-height:1.6;color:#64748B;">'
                .'We will send a gentle reminder closer to the time. Thank you for your understanding.'
                .'</td></tr></table>';

            $send($parents, 'We will be closed on '.$when.($centreName ? ' — '.$centreName : ''), $body, [
                'eyebrow' => 'CENTRE CLOSURE',
                'title' => 'We will be closed on '.$when,
                'subtitle' => $centreName ?: '',
                // What they need to do, in the line the inbox shows before opening it.
                'preheader' => 'Your child’s place is unaffected — but you will need care for '.$when.'.',
            ]);
        }

        if (isset($staffCentreName)) { $centreName = $staffCentreName; }   // staff keep the centre's own name

        // ── EDUCATORS — the rota, not the doors ─────────────────────────────
        // (Not on a closure added afterwards: they were told about the leave already.)
        if ($centreId && $only !== 'closure') {
            $educators = DB::table('users as u')->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
                ->where('ra.centre_id', $centreId)->where('ra.active', true)
                ->whereIn('ra.role', ['educator', 'home_visitor'])
                ->whereNull('u.deleted_at')->where('u.id', '!=', $row->user_id)
                ->distinct()->pluck('u.email')->all();

            $body = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
                .'<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 12px;">'
                .'<strong>'.$e($who).'</strong> has approved time off, so they will not be in on these dates.'
                .'</td></tr>'
                .$panel('#E0F2FE', '#075985', 'Away', $when)
                .'<tr><td style="padding:14px 0 0;font-size:14px;line-height:1.6;color:#334155;">'
                .($closedCentreId
                    ? 'The centre is closed for these dates, so no cover is needed.'
                    : 'Please check the rota — cover may need arranging.')
                .'</td></tr>'
                .'<tr><td style="padding:14px 0 0;font-size:14px;line-height:1.6;color:#64748B;">'
                .'It is on the staff calendar in KiddieTrac.</td></tr></table>';

            $send($educators, $who.' is away — '.$when, $body, [
                'eyebrow' => 'ROTA CHANGE',
                'title' => $who.' is away',
                'subtitle' => $when,
                'preheader' => $who.' is off on '.$when.'.',
            ]);
        }

        // ── ADMIN AND DIRECTORS — the record of what it did ─────────────────
        $admins = \App\Support\CentreScope::managers((int) $agencyId, $centreId ?: $closedCentreId)
            ->distinct()->pluck('u.email')->all();

        $parentCount = $closedCentreId ? $this->closureParentIds($closedCentreId)->count() : 0;

        $body = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
            .'<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 12px;">'
            .'<strong>'.$e($who).'</strong>’s time off was approved'
            .(trim(($actor->first_name ?? '').' '.($actor->last_name ?? ''))
                ? ' by '.$e(trim(($actor->first_name ?? '').' '.($actor->last_name ?? ''))) : '')
            .'.</td></tr>'
            .$panel('#DCFCE7', '#15803D', 'Approved', $when)
            .'<tr><td style="padding:14px 0 0;font-size:14px;line-height:1.6;color:#334155;">'
            .($closedCentreId
                ? ($centreName ? $e($centreName) : 'The centre').' is now marked <strong>closed</strong> for these dates and '
                  .$parentCount.' parent'.($parentCount === 1 ? '' : 's').' '
                  .($parentCount ? 'have been told' : 'were found to tell')
                  .'. Reminders follow on the usual schedule.'
                : 'No closure was created — the centre has other staff, so cover applies.')
            .'</td></tr></table>';

        if ($only === 'closure') {
            // A closure added to leave that was already approved: say that, and who did it.
            $by = trim(($actor->first_name ?? '').' '.($actor->last_name ?? ''));
            $body = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
                .'<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 12px;">'
                .($centreName ? '<strong>'.$e($centreName).'</strong>' : 'The centre').' has been marked <strong>closed</strong>'
                .($by ? ' by '.$e($by) : '').' for <strong>'.$e($who).'</strong>’s approved time off.</td></tr>'
                .$panel('#FEF3C7', '#B45309', 'Closed', $when)
                .'<tr><td style="padding:14px 0 0;font-size:14px;line-height:1.6;color:#334155;">'
                .$parentCount.' parent'.($parentCount === 1 ? '' : 's').' '
                .($parentCount ? 'have been told' : 'were found to tell').'. Reminders follow on the usual schedule.'
                .'</td></tr></table>';
            $send($admins, 'Closed: '.($centreName ?: 'centre').' — '.$when, $body, [
                'eyebrow' => 'CENTRE CLOSURE',
                'title' => ($centreName ?: 'Centre').' closed',
                'subtitle' => $when,
                'preheader' => ($centreName ?: 'The centre').' is closed '.$when.' — parents notified.',
            ]);

            return;
        }

        $send($admins, 'Approved: '.$who.' off '.$when, $body, [
            'eyebrow' => 'TIME OFF APPROVED',
            'title' => $who.'’s time off approved',
            'subtitle' => $when,
            'preheader' => $who.' approved for '.$when
                .($closedCentreId ? ' — centre closed, parents notified.' : '.'),
        ]);
    }

    /**
     * Tell people the leave is off and the day is going ahead as normal.
     *
     * Approvers always hear: they made a decision that no longer stands, and the rota
     * may have been built around it.
     *
     * Parents hear ONLY if a closure reminder had already reached them. If nobody was
     * ever told the centre would be shut, an email saying it is open after all describes
     * a closure they never knew about — which is worse than saying nothing.
     */
    private function announceWithdrawal(object $row, $actor, ?int $reopenedCentreId, bool $parentsHadBeenTold): void
    {
        $name = trim((($actor->first_name ?? '').' '.($actor->last_name ?? ''))) ?: 'A team member';
        $when = self::whenPhrase($row);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $body = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
            .'<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 12px;">'
            .$e($name).' has withdrawn their time-off request. They will be working as usual.</td></tr>'
            .'<tr><td style="padding:6px 0;"><div style="background:#DCFCE7;border-radius:10px;padding:14px 16px;'
            .'font-size:15px;color:#0F172A;"><strong style="color:#16A34A;text-transform:uppercase;font-size:12px;'
            .'letter-spacing:.06em;">Working as normal</strong><br>'
            .'<span style="font-size:15px;">'.$e($when).'</span></div></td></tr>'
            .($reopenedCentreId
                ? '<tr><td style="padding:14px 0 0;font-size:14px;line-height:1.6;color:#334155;">'
                  .'The closure for these dates has been removed and the calendar is back to normal.</td></tr>'
                : '')
            .'<tr><td style="padding:16px 0 0;font-size:14px;line-height:1.6;color:#64748B;">'
            .'Withdrawn on '.now()->format('j M Y').'. No action is needed.</td></tr></table>';

        $html = EmailTemplate::wrap((int) $row->agency_id, $body, [
            'eyebrow' => 'TIME OFF WITHDRAWN',
            'title' => $name.' will be in after all',
            'subtitle' => $when,
            'preheader' => $name.' has withdrawn their time off for '.$when.' and will be working as usual.',
        ]);
        $subject = $name.' will be in after all — '.$when;

        $to = [];

        // The approvers.
        foreach (DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.agency_id', $row->agency_id)
            ->whereIn('ra.role', ['centre_director', 'agency_admin'])
            ->where('ra.active', true)->whereNull('u.deleted_at')
            ->distinct()->pluck('u.email') as $addr) {
            $to[] = $addr;
        }

        // Colleagues at the centre, who may have been covering.
        if ($row->centre_id || $reopenedCentreId) {
            foreach (DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.centre_id', $reopenedCentreId ?: $row->centre_id)
                ->where('ra.role', 'educator')->where('ra.active', true)
                ->whereNull('u.deleted_at')->where('u.id', '!=', $row->user_id)
                ->distinct()->pluck('u.email') as $addr) {
                $to[] = $addr;
            }
        }

        // Parents — only where the closure had already been announced to them.
        if ($reopenedCentreId && $parentsHadBeenTold) {
            $familyIds = DB::table('families')->where('centre_id', $reopenedCentreId)
                ->whereNull('deleted_at')->pluck('id');
            foreach (DB::table('users as u')->join('guardians as g', 'g.user_id', '=', 'u.id')
                ->whereIn('g.family_id', $familyIds)->whereNull('u.deleted_at')
                ->distinct()->pluck('u.email') as $addr) {
                $to[] = $addr;
            }
        }

        foreach (array_unique(array_filter($to, fn ($a) => filter_var((string) $a, FILTER_VALIDATE_EMAIL))) as $addr) {
            try {
                \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($addr, $subject) {
                    $m->to($addr)->subject($subject);
                    // A rota change people need to act on, not bulk news.
                    $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
                });
            } catch (\Throwable $ex) {
                \Illuminate\Support\Facades\Log::warning('Withdrawal email failed', [
                    'to' => $addr, 'error' => $ex->getMessage(),
                ]);
            }
        }
    }

    private function mailDecision(object $row, string $status, string $when, ?string $notes, $decider): void
    {
        $u = DB::table('users')->where('id', $row->user_id)->first();
        if (! $u || ! filter_var((string) $u->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        if (\App\Support\Suppression::isUser((int) $u->id)) {
            return;
        }

        $approved = $status === 'approved';
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $by = trim((($decider->first_name ?? '') . ' ' . ($decider->last_name ?? ''))) ?: 'your centre';
        $accent = $approved ? '#16A34A' : '#B91C1C';
        $tint = $approved ? '#DCFCE7' : '#FEE2E2';

        // A declined request needs the reason more than an approved one does, but both
        // carry it when there is one — a decision with no explanation invites a second
        // request for the same dates.
        $notesRow = $notes
            ? '<tr><td style="padding:12px 0 0;font-size:14px;line-height:1.6;color:#334155;">'
              . '<strong>Note from ' . $e($by) . ':</strong> ' . $e($notes) . '</td></tr>'
            : '';

        $body = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
            . '<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 12px;">'
            . 'Your time-off request has been ' . ($approved ? 'approved' : 'declined') . '.</td></tr>'
            . '<tr><td style="padding:6px 0;"><div style="background:' . $tint . ';border-radius:10px;padding:14px 16px;'
            . 'font-size:15px;color:#0F172A;"><strong style="color:' . $accent . ';text-transform:uppercase;'
            . 'font-size:12px;letter-spacing:.06em;">' . ($approved ? 'Approved' : 'Declined') . '</strong><br>'
            . '<span style="font-size:15px;">' . $e(ucfirst((string) $row->request_type)) . ' · ' . $e($when)
            . '</span></div></td></tr>'
            . $notesRow
            . '<tr><td style="padding:16px 0 0;font-size:14px;line-height:1.6;color:#64748B;">'
            . 'Decided by ' . $e($by) . ' on ' . now()->format('j M Y') . '.'
            . ($approved ? ' It now shows on the staff calendar.' : ' Speak to your centre if you need to discuss it.')
            . '</td></tr></table>';

        try {
            $html = EmailTemplate::wrap((int) $row->agency_id, $body, [
                'eyebrow' => $approved ? 'APPROVED' : 'DECLINED',
                'title' => 'Your time-off request was ' . ($approved ? 'approved' : 'declined'),
                'subtitle' => $when,
                'preheader' => 'Your time off for ' . $when . ' was ' . ($approved ? 'approved' : 'declined'),
            ]);
            $name = trim((($u->first_name ?? '') . ' ' . ($u->last_name ?? '')));
            AgencyMailer::forAgency((int) $row->agency_id)->html($html, function ($m) use ($u, $name, $approved) {
                $m->to($u->email, $name ?: null)
                  ->subject('Your time-off request was ' . ($approved ? 'approved' : 'declined'));
            });
        } catch (\Throwable $ex) {
            \Illuminate\Support\Facades\Log::warning('Time-off decision email failed', [
                'user' => $u->id, 'error' => $ex->getMessage(),
            ]);
        }
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $row = DB::table('time_off_requests')->where('id', $id)->first();
        abort_unless($row, 404);
        abort_unless((int) $row->user_id === (int) $request->user()->id, 403, 'Only the requester can cancel');

        // An APPROVED request can be withdrawn too. Plans change, and the alternative
        // was asking an admin to edit the database. Only a request that has already
        // happened, or been declined, is beyond taking back.
        abort_unless(in_array((string) $row->status, ['pending', 'approved'], true), 422,
            'That request can no longer be withdrawn.');

        $wasApproved = (string) $row->status === 'approved';

        DB::table('time_off_requests')->where('id', $id)->update([
            'status' => 'cancelled', 'updated_at' => now(),
        ]);
        // KiddieTrac HR: withdrawn leave goes back on the balance.
        \App\Support\HrLeave::syncRequest(DB::table('time_off_requests')->where('id', $id)->first(), (int) $request->user()->id);

        // Put the calendar back. Only a closure this system created for exactly these
        // dates is removed — anything typed in by hand is somebody's deliberate entry
        // and is not ours to delete.
        $reopened = null;
        $parentsHadBeenTold = false;
        if ($wasApproved) {
            $closure = DB::table('centre_closures')
                ->where('closure_type', 'staff_leave')
                ->whereDate('closure_date', $row->start_at)
                ->whereDate('end_date', $row->end_at)
                ->when($row->centre_id, fn ($q) => $q->where('centre_id', $row->centre_id))
                ->first();

            if ($closure) {
                // Did anyone outside actually hear about this? reminders_sent lists the
                // lead times already emailed. Empty means the closure never left the
                // building, and there is nothing to correct.
                $parentsHadBeenTold = trim((string) ($closure->reminders_sent ?? '')) !== '';
                DB::table('centre_closures')->where('id', $closure->id)->delete();
                $reopened = (int) $closure->centre_id;
            }
        }

        $this->announceWithdrawal($row, $request->user(), $reopened, $parentsHadBeenTold);

        return response()->json([
            'status' => 'cancelled',
            'calendar_reverted' => (bool) $reopened,
            'parents_notified' => $parentsHadBeenTold,
        ]);
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
        abort_unless($first, 400, 'No agency context');
        return (int) $first;
    }

    /* A DIRECTOR DECIDES ONLY FOR THEIR OWN CENTRES (2026-11-05 leak sweep). assertAgencyAccess passed any
       centre director in the agency, so a director at one centre could approve another centre's leave and close
       that centre (emailing its families). Same rule as who is asked (TimeOffReminders::approvers): an agency
       admin of the request's agency, or a director of the request's centre (or of a centre the requester works
       at), and any centre being closed must be one they direct. A platform admin only in the agency they chose. */
    private function assertDecider(Request $request, object $row, ?int $closeCentre = null, bool $autoClose = false): ?array
    {
        $u = $request->user();
        $agencyId = (int) $row->agency_id;
        $roles = DB::table('role_assignments')->where('user_id', $u->id)->where('active', true)->get(['role', 'agency_id', 'centre_id']);
        if ($roles->contains(fn ($r) => $r->role === 'platform_admin')) {
            abort_unless($this->resolveAgencyId($request) === $agencyId, 403);
            return null;
        }
        if ($roles->contains(fn ($r) => $r->role === 'agency_admin' && (int) $r->agency_id === $agencyId)) {
            return null;
        }
        $inAgency = \App\Support\AgencyRole::centreIds($agencyId);
        $directs = $roles->filter(fn ($r) => $r->role === 'centre_director' && $r->centre_id)
            ->map(fn ($r) => (int) $r->centre_id)->filter(fn ($c) => in_array($c, $inAgency, true))->unique()->values()->all();
        $requestCentres = $row->centre_id ? [(int) $row->centre_id]
            : DB::table('role_assignments')->whereIn('role', \App\Support\Visibility::STAFF_ROLES)->where('user_id', $row->user_id)
                ->where('active', true)->whereIn('centre_id', $inAgency ?: [0])->pluck('centre_id')
                // ...and the centres of the rooms they cover: a home visitor has no centre row at all.
                ->merge(DB::table('educator_rooms as er')->join('rooms as r', 'r.id', '=', 'er.room_id')
                    ->where('er.user_id', $row->user_id)->whereIn('r.centre_id', $inAgency ?: [0])->pluck('r.centre_id'))
                ->map(fn ($v) => (int) $v)->unique()->values()->all();
        if (! array_intersect($directs, $requestCentres)) {
            /* HR4B6 (2026-11-27): an approver grant for this person, or cover for a director who is away
               (App\Support\HrApprovers). Never more than the grant gives: a cover closes only the centres the
               director it covers directs; a 'people' grant never closes one ("auto" then simply does not close). */
            $via = \App\Support\HrApprovers::via((int) $u->id, $row);
            abort_unless($via, 403, 'You can only decide time off for a centre you direct.');
            if ($closeCentre && ! in_array((int) $closeCentre, $via['close'], true)) {
                abort_unless($autoClose, 403, 'You can only close a centre you direct.');
                $via['no_close'] = true;
            }

            return $via;
        }
        abort_if($closeCentre && ! \App\Support\CentreScope::canWrite($directs, $closeCentre), 403, 'You can only close a centre you direct.');

        return null;
    }

    /**
     * HR4B6: may this approver decide THIS request from an emailed button? false = no; null = the ordinary rule
     * (an admin of the agency, a platform admin, or a director of the request's centre); an array = through a grant.
     */
    private function emailDecider(int $userId, object $row)
    {
        $agencyId = (int) $row->agency_id;
        $roles = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)->get(['role', 'agency_id', 'centre_id']);
        if ($roles->contains(fn ($r) => $r->role === 'platform_admin' || ($r->role === 'agency_admin' && (int) $r->agency_id === $agencyId))) return null;
        if (array_intersect(\App\Support\HrApprovers::directs($userId, $agencyId), \App\Support\HrApprovers::requestCentres($row))) return null;
        $via = \App\Support\HrApprovers::via($userId, $row);

        return $via ?: false;
    }

    private function noteGrantDecision(object $row, array $via, string $status, int $actorId): void
    {
        try {
            \App\Support\Audit::write(['user_id' => $actorId, 'agency_id' => (int) $row->agency_id, 'action' => 'hr.time_off_decided_via_grant',
                'entity_type' => 'time_off_request', 'entity_id' => (int) $row->id,
                'payload' => json_encode(['status' => $status, 'kind' => $via['kind'], 'grant_id' => $via['grant'], 'covering_for' => $via['for']])]);
        } catch (\Throwable $e) {
        }
    }

    private function assertAgencyAccess(Request $request, int $agencyId): void
    {
        $u = $request->user();
        $isPlatform = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform) return;
        $hasRole = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('agency_id', $agencyId)->whereIn('role', ['agency_admin', 'centre_director'])
            ->where('active', true)->exists();
        abort_unless($hasRole, 403);
    }
}
