<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 2026-07-13 — Self-scoped staff endpoints for the educator mobile app.
 *
 * Educators had no way to see their own schedule or the record of a child in
 * their room: every existing route for this data is director/agency-admin gated
 * and centre-wide (/director/schedule, /director/timesheets, /admin/payroll all
 * return EVERY staff member's hours). Rather than widen those — which would let
 * an educator read colleagues' payroll — these return only the caller's own
 * data, and child records only for children the caller can already access.
 *
 * Endpoints:
 *   GET /provider/shifts/me        The caller's own upcoming + recent shifts
 *   GET /provider/children/{child} One child's record — the safety-critical bits
 *                                  a room educator needs (allergies, medical
 *                                  alerts, who may collect them, who to call)
 *
 * "Payroll" here is hours, not money: there is no pay-rate column anywhere in
 * the schema, so the app shows hours worked (from /staff/punches/me, which is
 * already self-scoped) and this adds the scheduled side of the same picture.
 */
class EducatorSelfController extends Controller
{
    use ResolvesCentreContext;

    /** The caller's own shifts. Never anyone else's — user_id is forced to the caller. */
    public function myShifts(Request $request): JsonResponse
    {
        $user = $request->user();
        $from = $request->filled('from')
            ? Carbon::parse((string) $request->input('from'))->startOfDay()
            : Carbon::now()->startOfWeek();
        $to = $request->filled('to')
            ? Carbon::parse((string) $request->input('to'))->endOfDay()
            : Carbon::now()->addWeeks(4)->endOfDay();

        $shifts = DB::table('shifts as s')
            ->leftJoin('rooms as r', 'r.id', '=', 's.room_id')
            ->where('s.user_id', $user->id)
            ->whereBetween('s.starts_at', [$from, $to])
            ->orderBy('s.starts_at')
            ->select([
                's.id', 's.starts_at', 's.ends_at', 's.role', 's.status',
                's.room_id', 'r.name as room_name', 'r.color_hex as room_colour',
            ])
            ->get()
            ->map(function ($s) {
                $start = Carbon::parse($s->starts_at);
                $end = $s->ends_at ? Carbon::parse($s->ends_at) : null;
                $s->date = $start->toDateString();
                $s->hours = $end ? round($start->floatDiffInHours($end), 2) : null;
                return $s;
            });

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'shifts' => $shifts,
            'scheduled_hours' => round((float) $shifts->sum('hours'), 2),
        ]);
    }

    /**
     * All children currently enrolled in the caller's centre(s)/room(s). For pickers
     * like Report cards where a room educator must choose one of THEIR children —
     * the admin-only /admin/children 403s for educators, leaving the list empty.
     * Self-scoped: only the educator's own centres (role_assignments + educator_rooms).
     */
    public function children(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $centreIds = DB::table('role_assignments')
            ->where('user_id', $userId)->where('active', 1)->whereNotNull('centre_id')
            ->pluck('centre_id')->map(fn ($v) => (int) $v)->all();
        $roomCentreIds = DB::table('educator_rooms as er')
            ->join('rooms as r', 'r.id', '=', 'er.room_id')
            ->where('er.user_id', $userId)
            ->pluck('r.centre_id')->map(fn ($v) => (int) $v)->all();
        $centreIds = array_values(array_unique(array_filter(array_merge($centreIds, $roomCentreIds))));
        if (empty($centreIds)) {
            return response()->json(['children' => []]);
        }

        $rows = DB::table('children as c')
            ->join('enrollments as e', 'e.child_id', '=', 'c.id')
            ->join('rooms as r', 'r.id', '=', 'e.room_id')
            ->tap(fn ($q) => \App\Support\CareSchedule::constrain($q, 'e'))
            ->leftJoin('centres as ce', 'ce.id', '=', 'r.centre_id')
            ->whereIn('r.centre_id', $centreIds)
            // A suspended family is hidden from the people delivering care, not just
            // from its own login.
            ->whereNotExists(fn ($q) => $q->from('families')
                ->whereColumn('families.id', '=', 'c.family_id')
                ->whereNotNull('families.suspended_at'))
            ->whereNull('e.end_date')
            ->whereNull('c.deleted_at')
            ->distinct()
            ->orderBy('c.first_name')
            ->select('c.id', 'c.first_name', 'c.last_name', 'c.preferred_name', 'c.photo_url', 'ce.name as centre_name')
            ->get();

        return response()->json(['children' => $rows]);
    }

    /**
     * One child's record, for staff who already have access to that child.
     * canAccessChildScoped() is the audited tenant-isolation check (agency +
     * centre + family), so a child from another centre or agency 403s here.
     */
    public function childRecord(Request $request, int $child): JsonResponse
    {
        if (! $this->canAccessChildScoped($request, $child)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $row = DB::table('children as c')
            ->leftJoin('rooms as r', 'r.id', '=', 'c.primary_room_id')
            ->leftJoin('families as f', 'f.id', '=', 'c.family_id')
            ->where('c.id', $child)
            // A suspended family is hidden from the people delivering care, not just
            // from its own login.
            ->whereNotExists(fn ($q) => $q->from('families')
                ->whereColumn('families.id', '=', 'c.family_id')
                ->whereNotNull('families.suspended_at'))
            ->whereNull('c.deleted_at')
            ->select([
                'c.id', 'c.first_name', 'c.last_name', 'c.preferred_name', 'c.pronouns', 'c.gender',
                'c.date_of_birth', 'c.photo_url', 'c.allergies', 'c.medical_notes',
                'c.dietary_restrictions', 'c.dietary_notes', 'c.health_alerts',
                'c.cultural_notes', 'c.preferred_lang', 'c.doctor_name', 'c.doctor_phone',
                'c.enrollment_status', 'c.family_id',
                // The room plans the day around these; the educator record showed
                // everything else about a child except when they are expected.
                'c.expected_dropoff_time', 'c.expected_pickup_time',
                'r.name as room_name', 'r.color_hex as room_colour',
                'f.family_name', 'f.primary_phone', 'f.primary_email',
            ])
            ->first();

        if (! $row) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $dob = $row->date_of_birth ? Carbon::parse($row->date_of_birth) : null;
        $row->age_human = $dob ? $this->ageHuman($dob) : null;

        // TIME columns come back as "09:00:00"; the screen wants "09:00". These are
        // wall-clock values, so there is deliberately no timezone handling here.
        $row->expected_dropoff_time = $row->expected_dropoff_time
            ? substr((string) $row->expected_dropoff_time, 0, 5) : null;
        $row->expected_pickup_time = $row->expected_pickup_time
            ? substr((string) $row->expected_pickup_time, 0, 5) : null;

        // Guardians — name + phone only. An educator needs to reach a parent, not
        // to read their billing split or their address.
        $guardians = DB::table('guardians as g')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.family_id', $row->family_id)
            ->whereNull('u.deleted_at')
            ->orderByDesc('g.is_primary')
            ->select([
                'u.id', 'u.first_name', 'u.last_name', 'u.phone', 'u.email', 'u.photo_url',
                'g.relationship', 'g.is_primary', 'g.can_pickup',
            ])
            ->get();

        // emergency_contacts hangs off the FAMILY, not the child.
        $emergency = DB::table('emergency_contacts')
            ->where('family_id', $row->family_id)
            ->select(['id', 'name', 'relationship', 'phone', 'alt_phone', 'can_pickup', 'notes'])
            ->get();

        $pickup = DB::table('pickup_authorizations')
            ->where('child_id', $child)
            ->where('active', 1)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', Carbon::now()->toDateString());
            })
            ->select(['id', 'full_name', 'relationship', 'phone', 'photo_id_url', 'expires_at', 'notes'])
            ->get();

        // The child's own record of everything logged about them — care moments
        // AND every sign-in/sign-out, each with WHO recorded it and when. This is
        // the compliance trail: "who had this child, and when" has to be
        // answerable from the child's record, not reconstructed from three screens.
        $tz = 'America/Toronto';
        $agencyTz = DB::table('children as c')
            ->leftJoin('families as f', 'f.id', '=', 'c.family_id')
            ->leftJoin('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->leftJoin('agencies as a', 'a.id', '=', 'ce.agency_id')
            ->where('c.id', $child)
            ->value('a.timezone');
        if ($agencyTz) $tz = $agencyTz;

        $byName = "NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),'')";

        $checks = DB::table('check_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.by_user_id')
            ->where('e.child_id', $child)
            ->orderByDesc('e.occurred_at')
            ->limit(120)
            ->get([
                'e.id', 'e.event_type', 'e.occurred_at', 'e.notes',
                DB::raw("$byName as by_name"),
            ])
            ->map(fn ($e) => (object) [
                'kind' => $e->event_type,          // check_in | check_out
                'group' => 'attendance',
                'detail' => null,
                'note' => $e->notes,
                'by' => $e->by_name,
                // ISO-8601 with offset, not a bare wall clock: a zone-less datetime is
                // indistinguishable from the UTC ones elsewhere in this API, and a client
                // that guesses UTC renders it hours early. See CareController.
                'at' => \Carbon\Carbon::parse($e->occurred_at)->utc()->toIso8601ZuluString(),
                'time_display' => \App\Support\AgencyTime::fmt(\Carbon\Carbon::parse($e->occurred_at), $tz),
            ]);

        $careLogs = DB::table('daily_care_logs as l')
            ->leftJoin('users as u', 'u.id', '=', 'l.recorded_by_id')
            ->where('l.child_id', $child)
            ->orderByDesc('l.occurred_at')
            ->limit(120)
            ->get([
                'l.log_type', 'l.occurred_at', 'l.details', 'l.notes',
                DB::raw("$byName as by_name"),
            ])
            ->map(fn ($l) => (object) [
                'kind' => $l->log_type,
                'group' => 'care',
                'detail' => $l->details,
                'note' => $l->notes,
                'by' => $l->by_name,
                'at' => \Carbon\Carbon::parse($l->occurred_at)->utc()->toIso8601ZuluString(),
                'time_display' => \App\Support\AgencyTime::fmt(\Carbon\Carbon::parse($l->occurred_at), $tz),
            ]);

        // The roster quick-log writes to daily_events, not daily_care_logs — read
        // both or half the child's history is missing.
        $eventLogs = DB::table('daily_events as d')
            ->leftJoin('users as u', 'u.id', '=', 'd.recorded_by_id')
            ->where('d.child_id', $child)
            ->whereNull('d.deleted_at')
            ->whereIn('d.event_type', ['diaper', 'bathroom', 'nap', 'meal', 'snack', 'bottle', 'sunscreen', 'mood', 'outdoor'])
            ->orderByDesc('d.occurred_at')
            ->limit(120)
            ->get([
                'd.event_type', 'd.occurred_at', 'd.payload', 'd.notes',
                DB::raw("$byName as by_name"),
            ])
            ->map(function ($d) use ($tz) {
                $detail = null;
                $p = json_decode((string) $d->payload, true);
                if (is_array($p)) {
                    $vals = array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', array_values($p)));
                    $detail = $vals ? implode(', ', $vals) : null;
                }
                return (object) [
                    'kind' => $d->event_type,
                    'group' => 'care',
                    'detail' => $detail,
                    'note' => $d->notes,
                    'by' => $d->by_name,
                    'at' => \Carbon\Carbon::parse($d->occurred_at)->utc()->toIso8601ZuluString(),
                    'time_display' => \App\Support\AgencyTime::fmt(\Carbon\Carbon::parse($d->occurred_at), $tz),
                ];
            });

        // Honour the agency's retention policy: the child's record only shows logs
        // inside the "Attendance & daily logs" window configured under Data
        // Retention & Compliance (agencies.settings -> compliance.daily_log_months,
        // 36 months by default). Older entries are past the period the agency says
        // it keeps them for, so they are not surfaced here.
        $months = $this->retentionMonths($child);
        $cutoff = \Illuminate\Support\Carbon::now($tz)->subMonths($months)->format('Y-m-d H:i:s');

        $all = $checks->concat($careLogs)->concat($eventLogs)->sortByDesc('at')->values();
        $history = $all->filter(fn ($h) => $h->at >= $cutoff)->values();

        return response()->json([
            'child' => $row,
            'guardians' => $guardians,
            'emergency_contacts' => $emergency,
            'pickup_authorizations' => $pickup,
            'history' => $history,
            'history_total' => $all->count(),
            'retention_months' => $months,
            'timezone' => $tz,
        ]);
    }

    /** The agency's "Attendance & daily logs" retention window, in months. */
    private function retentionMonths(int $childId): int
    {
        $settings = DB::table('children as c')
            ->leftJoin('families as f', 'f.id', '=', 'c.family_id')
            ->leftJoin('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->leftJoin('agencies as a', 'a.id', '=', 'ce.agency_id')
            ->where('c.id', $childId)
            ->value('a.settings');

        $decoded = $settings ? (json_decode((string) $settings, true) ?: []) : [];
        $months = (int) ($decoded['compliance']['daily_log_months'] ?? 36);

        return $months > 0 ? $months : 36;
    }

    private function ageHuman(Carbon $dob): string
    {
        $months = (int) $dob->diffInMonths(Carbon::now());
        if ($months < 24) {
            return $months . 'm';
        }
        $years = intdiv($months, 12);
        $rem = $months % 12;
        return $rem ? ($years . 'y ' . $rem . 'm') : ($years . 'y');
    }

    /**
     * How today is going, for the educator asking.
     *
     * The same numbers and the same score the evening summary email uses — literally the
     * same methods — so the tile on Today and the email that arrives at 11pm cannot
     * disagree about the day the person just had.
     *
     * Live, so it climbs as the day is logged. That is the point of putting it on Today
     * rather than only in the email: something to move, not just a verdict afterwards.
     */
    /**
     * GET /educator/forms-today — the forms this educator is expected to submit today,
     * and which of them they actually have.
     *
     * Submitting a daily form was invisible the moment it was done: the signoff went into
     * managed_form_signoffs and nothing on any screen said so. An educator could not tell
     * what was still owed, and a director could not tell who was behind without opening
     * each form in turn.
     *
     * The distinction that matters here is STARTED vs SUBMITTED. A signoff row is created
     * when the form is opened and saved as a draft, and only signed_at means it was
     * actually submitted — on the day this was written, 4 of the 10 entries were drafts
     * nobody had finished, and one educator had submitted neither of her two. A count that
     * treated a draft as done would have reported that day as complete.
     *
     * Accepts ?user_id= so a director can see any educator at their own centres, and
     * ?date= (YYYY-MM-DD) to look back.
     */
    public function formsToday(Request $request): JsonResponse
    {
        $me = $request->user();

        $targetId = (int) ($request->query('user_id') ?: $me->id);
        $target = $targetId === (int) $me->id ? $me : DB::table('users')->find($targetId);
        if (! $target) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $myRoles = DB::table('role_assignments')->where('user_id', $me->id)->where('active', true)->get();
        $agencyId = (int) ($myRoles->firstWhere('agency_id', '!=', null)->agency_id ?? 0);

        /* Looking at somebody else is a supervisory act, so it needs a supervisory role
           in the SAME agency — otherwise this would report one agency's staff to another. */
        if ($targetId !== (int) $me->id) {
            $canSupervise = $myRoles->contains(fn ($r) => in_array($r->role, ['agency_admin', 'centre_director', 'platform_admin'], true));
            $sameAgency = DB::table('role_assignments')->where('user_id', $targetId)
                ->where('active', true)->where('agency_id', $agencyId)->exists();
            if (! $canSupervise || ! $sameAgency) {
                return response()->json(['message' => 'Not permitted'], 403);
            }
        }

        $targetRoles = DB::table('role_assignments')->where('user_id', $targetId)
            ->where('active', true)->get();
        $targetAgencyId = (int) ($targetRoles->firstWhere('agency_id', '!=', null)->agency_id ?? $agencyId);
        $centreId = $targetRoles->firstWhere('centre_id', '!=', null)->centre_id ?? null;

        $tz = \App\Support\AgencyTime::tzForCentre($centreId ? (int) $centreId : null);
        $day = $request->query('date')
            ? Carbon::parse($request->query('date'), $tz)->startOfDay()
            : Carbon::now($tz)->startOfDay();

        /* The rows are stored in UTC (app.timezone is UTC), so "today" has to be a UTC
           RANGE derived from the centre's own midnight — comparing DATE(created_at)
           against the local date would put an evening submission on the wrong day. */
        $fromUtc = $day->copy()->utc();
        $toUtc = $day->copy()->endOfDay()->utc();

        /* WHICH FORMS ARE THIS PERSON'S, on the portal's one rule — named recipients
           narrow a form to exactly those people, and a form naming nobody with no
           audience reaches nobody. See App\Support\FormAudience.

           This decided for itself before, and got both halves wrong: it never looked
           at named recipients at all, and it read "no audience" as EVERYBODY. Two
           readings of the same table in one product, and this was the permissive one.

           staffRolesOf() drops `guardian` on purpose. This is a shift checklist. An
           educator who is also a parent at the centre holds a guardian role, and with
           it every parent form landed in her daily task list — which is how iLearn's
           Infant Feeding Plan, addressed to one family, ended up with a draft on it
           from a member of staff. Her own family's forms are still hers; they belong
           in the parent portal, not in "what I must complete today". */
        $roleNames = \App\Support\FormAudience::staffRolesOf($targetId);

        $allForms = DB::table('managed_forms')
            ->where('agency_id', $targetAgencyId)
            ->where('active', 1)
            ->orderBy('title')
            ->get(['id', 'title', 'audiences', 'fillable']);
        $forms = \App\Support\FormAudience::filter($allForms, $targetId, $roleNames);

        $expected = [];
        foreach ($forms as $f) {

            $signoff = DB::table('managed_form_signoffs')
                ->where('managed_form_id', $f->id)
                ->where('user_id', $targetId)
                ->whereBetween('created_at', [$fromUtc, $toUtc])
                ->orderByDesc('id')
                ->first();

            $state = 'not_started';
            $at = null;
            if ($signoff && $signoff->signed_at) {
                $state = 'submitted';
                $at = Carbon::parse($signoff->signed_at, 'UTC')->setTimezone($tz)->format('H:i');
            } elseif ($signoff) {
                $state = 'draft';
                $at = Carbon::parse($signoff->created_at, 'UTC')->setTimezone($tz)->format('H:i');
            }

            $expected[] = [
                'id' => (int) $f->id,
                'title' => $f->title,
                'state' => $state,
                'at' => $at,
                'signoff_id' => $signoff->id ?? null,
            ];
        }

        $submitted = count(array_filter($expected, fn ($f) => $f['state'] === 'submitted'));
        $drafts = count(array_filter($expected, fn ($f) => $f['state'] === 'draft'));

        return response()->json([
            'date' => $day->toDateString(),
            'timezone' => $tz,
            'user_id' => $targetId,
            'user_name' => trim(($target->first_name ?? '').' '.($target->last_name ?? '')),
            'expected' => count($expected),
            'submitted' => $submitted,
            'drafts' => $drafts,
            'forms' => $expected,
        ]);
    }

    /**
     * GET /admin/forms-today — every educator in scope, and what they have submitted today.
     *
     * The per-educator card answers "what do I still owe?". This answers the supervisor's
     * question, which is different: "who is behind?" Without it a director had to open each
     * form and read its signoff list to find the one person who had not filed.
     *
     * Same STARTED vs SUBMITTED distinction as formsToday — a draft is not a submission,
     * and the day this was written 4 of 10 entries agency-wide were drafts nobody finished.
     */
    /**
     * The active agency, resolved exactly as OperationsController::resolveAgencyId does.
     *
     * Kept identical on purpose — there is no shared trait for this yet, and every copy
     * that drifted is how a tenant guard ends up with eight different behaviours. Two
     * rules matter and both are deliberate:
     *   • the X-Active-Agency-Id header is honoured only for a platform_admin or somebody
     *     holding an active role in THAT agency (v22p94);
     *   • a platform_admin with no valid selection is REFUSED rather than defaulted to
     *     their first role's agency (v22p98) — defaulting leaked agency-scoped data to a
     *     super-admin on any header-less call.
     */
    private function resolveAgencyIdStrict(Request $request): int
    {
        $userId = $request->user()->id;
        $activeId = (int) $request->header('X-Active-Agency-Id');

        if ($activeId && DB::table('role_assignments')->where('user_id', $userId)->where('active', true)
            ->where(function ($w) use ($activeId) {
                $w->where('agency_id', $activeId)->orWhere('role', 'platform_admin');
            })->exists()) {
            return $activeId;
        }

        if (DB::table('role_assignments')->where('user_id', $userId)
            ->where('role', 'platform_admin')->where('active', true)->exists()) {
            abort(400, 'Select an agency first.');
        }

        $first = DB::table('role_assignments')->where('user_id', $userId)
            ->where('active', true)->value('agency_id');
        abort_unless($first, 400);

        return (int) $first;
    }

    public function formsTodayRollup(Request $request): JsonResponse
    {
        $me = $request->user();

        $myRoles = DB::table('role_assignments')->where('user_id', $me->id)->where('active', true)->get();
        $isAdmin = $myRoles->contains(fn ($r) => in_array($r->role, ['agency_admin', 'platform_admin'], true));
        $isDirector = $myRoles->contains(fn ($r) => $r->role === 'centre_director');
        if (! $isAdmin && ! $isDirector) {
            return response()->json(['message' => 'Not permitted'], 403);
        }

        /* SCOPED TO THE ACTIVE AGENCY. Uses the portal's own resolver rather than a
           local reading of the header — my first version fell back to the caller's first
           role's agency when the header was absent, which is precisely the leak v22p98
           closed for every other controller. This reports one member of staff's
           compliance record to another, so it gets the established guard, not a new one. */
        $agencyId = $this->resolveAgencyIdStrict($request);

        $agencyCentreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();

        // A director sees their own centres; an admin sees the agency's.
        $scopeCentreIds = $agencyCentreIds;
        if ($isDirector && ! $isAdmin) {
            $mine = $myRoles->where('role', 'centre_director')->pluck('centre_id')->filter()->all();
            $scopeCentreIds = array_values(array_intersect($agencyCentreIds, $mine));
        }
        /* ?centre_id= narrows to ONE provider, for the Daily Overview screen which is
           always looking at a single provider on a single day. Intersected with the scope
           above rather than replacing it — a centre id in the query string must never be
           able to reach outside what this caller is already allowed to see. */
        $requestedCentre = (int) $request->query('centre_id', 0);
        if ($requestedCentre) {
            $scopeCentreIds = array_values(array_intersect($scopeCentreIds, [$requestedCentre]));
            if (! $scopeCentreIds) {
                return response()->json(['message' => 'Not permitted for that centre'], 403);
            }
        }

        if (! $scopeCentreIds) {
            return response()->json(['date' => null, 'staff' => [], 'expected' => 0]);
        }

        $tz = \App\Support\AgencyTime::tzForCentre((int) $scopeCentreIds[0]);
        $day = $request->query('date')
            ? Carbon::parse($request->query('date'), $tz)->startOfDay()
            : Carbon::now($tz)->startOfDay();
        $fromUtc = $day->copy()->utc();
        $toUtc = $day->copy()->endOfDay()->utc();

        $staffRows = DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.agency_id', $agencyId)
            ->where('ra.active', true)
            ->whereIn('ra.role', ['educator'])
            ->whereIn('ra.centre_id', $scopeCentreIds)
            ->whereNull('u.deleted_at')
            ->where('u.status', 'active')
            ->distinct()
            ->orderBy('u.first_name')
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.photo_url', 'u.email', 'ra.centre_id']);

        /* ?provider_only=1 — just the provider, not everyone attached to their centre.
           A home-childcare centre IS one provider, and the Daily Overview is a view of
           that one person's day; but several educators can hold a role at the same
           centre (centre 14 has three), and listing all of them there reads as the whole
           agency's compliance rather than this provider's.

           The provider is the user whose email matches `centres.email` — the same match
           the Daily Overview screen already uses for the provider's photo and name.
           Compared case-insensitively, because they genuinely differ in the data
           ("schnarr.c@" on the centre vs "Schnarr.c@" on the user).

           Falls back to everyone if no user matches, rather than rendering an empty
           card: a centre whose email does not line up with an account is a data problem
           to notice, not a reason to show nothing. */
        $providerOnly = filter_var($request->query('provider_only', false), FILTER_VALIDATE_BOOLEAN);
        if ($providerOnly && $requestedCentre) {
            $centreEmail = strtolower(trim((string) DB::table('centres')
                ->where('id', $requestedCentre)->value('email')));
            if ($centreEmail !== '') {
                $justProvider = $staffRows->filter(
                    fn ($u) => strtolower(trim((string) $u->email)) === $centreEmail
                )->values();
                if ($justProvider->count()) {
                    $staffRows = $justProvider;
                }
            }
        }

        $forms = DB::table('managed_forms')
            ->where('agency_id', $agencyId)->where('active', 1)
            ->orderBy('title')->get(['id', 'title', 'audiences']);

        /* Only the forms an educator is actually asked for.

           A ROLLUP, so it asks the question once for the role rather than per person:
           a form addressed to named individuals is not an educator-wide expectation
           and does not belong in a "who has done today's forms" grid, and a form with
           no audience reaches nobody rather than everybody. Same rule as the
           per-person view above — see App\Support\FormAudience. */
        $namedAnywhere = \App\Support\FormAudience::namedMap($forms->pluck('id')->all());
        $forEducators = $forms->filter(function ($f) use ($namedAnywhere) {
            if (! empty($namedAnywhere[(int) $f->id])) {
                return false;
            }

            return in_array('educator', \App\Support\FormAudience::audiencesOf($f), true);
        })->values();

        $formIds = $forEducators->pluck('id')->all();
        $userIds = $staffRows->pluck('id')->unique()->values()->all();

        // One query for the whole day rather than one per person per form.
        $signoffs = ($formIds && $userIds)
            ? DB::table('managed_form_signoffs')
                ->whereIn('managed_form_id', $formIds)
                ->whereIn('user_id', $userIds)
                ->whereBetween('created_at', [$fromUtc, $toUtc])
                ->orderBy('id')
                ->get(['managed_form_id', 'user_id', 'signed_at', 'created_at'])
                ->groupBy(fn ($r) => $r->user_id.':'.$r->managed_form_id)
            : collect();

        $staff = [];
        foreach ($staffRows->unique('id') as $u) {
            $items = [];
            foreach ($forEducators as $f) {
                $row = optional($signoffs->get($u->id.':'.$f->id))->last();
                $state = 'not_started';
                $at = null;
                if ($row && $row->signed_at) {
                    $state = 'submitted';
                    $at = Carbon::parse($row->signed_at, 'UTC')->setTimezone($tz)->format('H:i');
                } elseif ($row) {
                    $state = 'draft';
                    $at = Carbon::parse($row->created_at, 'UTC')->setTimezone($tz)->format('H:i');
                }
                $items[] = ['id' => (int) $f->id, 'title' => $f->title, 'state' => $state, 'at' => $at];
            }

            $staff[] = [
                'user_id' => (int) $u->id,
                'name' => trim($u->first_name.' '.$u->last_name),
                'photo_url' => $u->photo_url,
                'centre_id' => (int) $u->centre_id,
                'submitted' => count(array_filter($items, fn ($i) => $i['state'] === 'submitted')),
                'drafts' => count(array_filter($items, fn ($i) => $i['state'] === 'draft')),
                'expected' => count($items),
                'forms' => $items,
            ];
        }

        /* Behind first — the whole point of this view is the person who has not filed.
           Sorting alphabetically would bury them among the people who are fine. */
        usort($staff, function ($a, $b) {
            $ao = $a['expected'] - $a['submitted'];
            $bo = $b['expected'] - $b['submitted'];
            return $ao === $bo ? strcmp($a['name'], $b['name']) : $bo <=> $ao;
        });

        return response()->json([
            'date' => $day->toDateString(),
            'timezone' => $tz,
            'expected' => $forEducators->count(),
            'staff_count' => count($staff),
            'complete' => count(array_filter($staff, fn ($s) => $s['submitted'] >= $s['expected'])),
            'staff' => $staff,
        ]);
    }

    public function dayScore(Request $request): JsonResponse
    {
        $user = $request->user();
        $centreId = DB::table('role_assignments')->where('user_id', $user->id)
            ->where('active', true)->whereNotNull('centre_id')->value('centre_id');
        $tz = \App\Support\AgencyTime::tzForCentre($centreId ? (int) $centreId : null);
        $date = \Illuminate\Support\Carbon::now($tz);

        $stats = \App\Console\Commands\EducatorDailySummaryCommand::statsFor((int) $user->id, $tz, $date);
        $score = \App\Console\Commands\EducatorDailySummaryCommand::dayScore($stats);

        return response()->json([
            'date' => $date->toDateString(),
            // A day the centre does not run should not be scored 0 out of 100 and shown as
            // a failure — there was nothing to do. The tile hides itself instead.
            'operating_day' => \App\Support\Closures::isOperatingDay($centreId ? (int) $centreId : null, $date->toDateString()),
            'score' => $score['score'],
            'label' => $score['label'],
            'colour' => $score['colour'],
            'blurb' => $score['blurb'],
            'stats' => [
                'moments' => $stats['moments'],
                'children' => $stats['children'],
                'observations' => $stats['observations'],
                'minutes' => $stats['minutes'],
                'photos' => $stats['media'],
            ],
        ]);
    }
}
