<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Which centres may a person see, given an agency that has already been resolved?
 *
 * The rule lives here rather than only in ResolvesCentreContext because not every
 * controller uses that trait — CalendarOverlayController and OperationsV2Controller
 * each carry their own private resolveAgencyId(), and adding the trait beside a
 * private method of the same name is asking for the shadowing bug that already cost
 * a day this week (a class's resolveCentreId(Child) silently overrode the trait's
 * resolveCentreId(User) and every guardian's incident list 500'd).
 *
 * So: one plain static, callable from anywhere, and the trait delegates to it. The
 * agency is an argument, not something this class resolves — deciding WHICH agency
 * is a separate question with its own header rules, and mixing the two is how a
 * tenant check ends up standing in for a person check.
 *
 * FAILS CLOSED. An unknown role, no agency, or a staff member with no centre
 * assignment returns []. Callers must treat [] as "no rows" — always `?: [0]` in
 * the whereIn — never as "no filter".
 */
final class Visibility
{
    /** Sees every centre in the agency. */
    private const AGENCY_WIDE = ['agency_admin', 'auditor'];

    /** Confined to the centres named on their own assignments. */
    private const CENTRE_SCOPED = ['centre_director', 'educator', 'home_visitor'];

    /* THE STAFF ROLES (2026-11-04 roles sweep). A parent's guardian role row carries a centre_id too, so "the centres
       I work at" read from role_assignments without a role filter handed parents staff views of their centre (the
       whole roster at /provider/children, classmates' birthdays at /provider/calendar/me). Every staff-centre lookup
       filters by this list: positive, so any role not named here gets no centres. */
    public const STAFF_ROLES = ['agency_admin', 'auditor', 'centre_director', 'educator', 'home_visitor'];

    /**
     * @param  int  $agencyId  already resolved, and already checked against the caller
     * @return int[] centre ids, always within that agency
     */
    public static function centreIds(int $agencyId, $user): array
    {
        if (! $user || ! $agencyId) {
            return [];
        }

        $inAgency = DB::table('centres')->where('agency_id', $agencyId)
            ->whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->all();

        $roles = DB::table('role_assignments')
            ->where('user_id', $user->id)->where('active', true)
            ->get(['role', 'centre_id', 'agency_id']);

        if ($roles->isEmpty()) {
            return [];
        }

        /* A platform admin is already confined to the agency they switched into —
           resolveAgencyId refuses to guess one for them — and sees all of it. */
        if ($roles->contains(fn ($r) => $r->role === 'platform_admin')) {
            return $inAgency;
        }

        if ($roles->contains(fn ($r) => in_array($r->role, self::AGENCY_WIDE, true)
                && (int) $r->agency_id === $agencyId)) {
            return $inAgency;
        }

        /* Intersected with the agency so a stale assignment cannot reach outside it. */
        $theirs = $roles
            ->filter(fn ($r) => in_array($r->role, self::CENTRE_SCOPED, true))
            ->pluck('centre_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();

        return array_values(array_intersect($theirs, $inAgency));
    }

    /** The same rule followed through to children, for child-shaped tables. */
    public static function childIds(int $agencyId, $user): array
    {
        $centreIds = self::centreIds($agencyId, $user);
        if (! $centreIds) {
            return [];
        }

        return DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->whereIn('f.centre_id', $centreIds)
            ->whereNull('c.deleted_at')
            ->pluck('c.id')->map(fn ($v) => (int) $v)->all();
    }

    /* THE CHILDREN A STAFF MEMBER LOOKS AFTER (2026-11-09, Anthony: "check into educators as we are noticing that the
       child count and list is not accurate ... add guards to never allow this to happen again"). Each educator-facing
       list had its own rule: the weekly overview showed an iLearn provider all 30 of the agency's children, allergy
       alerts showed every child's medical alerts agency-wide, the awards picker listed 29 children who had withdrawn
       (it read the family's centre and never the enrolment), and /provider/children dropped part-time children on
       their days off. One answer now, and kt:child-scope-check holds every list to it nightly.

       roomIds(): the rooms this person works in, inside ONE agency.
         - platform admin, or agency admin / auditor of this agency: every room of its open centres;
         - centre director: every room of the centres they direct;
         - educator: the rooms they were assigned to (educator_rooms), inside the centres where they hold the role -
           an assignment left behind at a centre they no longer work at gives nothing. No assignments at all = every
           room of their centres (the first-day fallback RoomController::bootstrap already applies);
         - home visitor: their assigned rooms only (their homes are other people's centres).
       FAILS CLOSED: [] means no rooms, and callers whereIn(... ?: [0]). */
    public static function roomIds(int $agencyId, $user): array
    {
        if (! $user || ! $agencyId) {
            return [];
        }
        $rooms = DB::table('rooms as r')->join('centres as c', 'c.id', '=', 'r.centre_id')
            ->where('c.agency_id', $agencyId)->where(fn ($q) => Live::centres($q, 'c'))
            ->get(['r.id', 'r.centre_id']);
        $roomsOf = fn (array $centres) => $rooms->filter(fn ($r) => in_array((int) $r->centre_id, $centres, true))
            ->pluck('id')->map(fn ($v) => (int) $v)->all();

        $roles = DB::table('role_assignments')->where('user_id', $user->id)->where('active', true)
            ->get(['role', 'centre_id', 'agency_id']);
        if ($roles->contains(fn ($r) => $r->role === 'platform_admin')
            || $roles->contains(fn ($r) => in_array($r->role, self::AGENCY_WIDE, true) && (int) $r->agency_id === $agencyId)) {
            return $rooms->pluck('id')->map(fn ($v) => (int) $v)->all();
        }

        $centresAs = fn (string $role) => $roles->filter(fn ($r) => $r->role === $role)
            ->pluck('centre_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $out = $roomsOf($centresAs('centre_director'));

        $assigned = DB::table('educator_rooms')->where('user_id', $user->id)->pluck('room_id')->map(fn ($v) => (int) $v)->all();
        $eduCentres = array_merge($centresAs('educator'), $centresAs('centre_director'));
        if ($roles->contains(fn ($r) => $r->role === 'educator')) {
            $out = array_merge($out, $assigned
                ? array_intersect($assigned, $roomsOf($eduCentres))
                : $roomsOf($centresAs('educator')));
        }
        if ($roles->contains(fn ($r) => $r->role === 'home_visitor')) {
            $out = array_merge($out, array_intersect($assigned, $rooms->pluck('id')->map(fn ($v) => (int) $v)->all()));
        }

        return array_values(array_unique(array_map('intval', $out)));
    }

    /** Children ENROLLED now (open enrolment, status enrolled, not deleted) in this person's rooms. Any weekday. */
    public static function liveChildIds(int $agencyId, $user): array
    {
        $rooms = self::roomIds($agencyId, $user);
        if (! $rooms) {
            return [];
        }

        return DB::table('children as c')->join('enrollments as e', 'e.child_id', '=', 'c.id')
            ->whereIn('e.room_id', $rooms)
            // Open = no end date, the rule the rosters use: ending a placement stamps today, and that child is not
            // in that room any more (a care-schedule move back on 2026-11-09 left two children in both homes otherwise).
            ->whereNull('e.end_date')
            ->where(fn ($q) => Live::enrolledChildren($q, 'c'))
            ->distinct()->pluck('c.id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * For planning lists that also carry children not yet in a room (waitlisted, starting soon): null = the whole
     * agency (admins), otherwise the live children in their rooms plus, for a director, every child still with us
     * whose family belongs to a centre they direct.
     */
    public static function planningChildIds(int $agencyId, $user): ?array
    {
        if (! $user || ! $agencyId) {
            return [];
        }
        $roles = DB::table('role_assignments')->where('user_id', $user->id)->where('active', true)->get(['role', 'centre_id', 'agency_id']);
        if ($roles->contains(fn ($r) => $r->role === 'platform_admin')
            || $roles->contains(fn ($r) => in_array($r->role, self::AGENCY_WIDE, true) && (int) $r->agency_id === $agencyId)) {
            return null;
        }
        $directs = $roles->filter(fn ($r) => $r->role === 'centre_director')->pluck('centre_id')->filter()->map(fn ($v) => (int) $v)->all();
        $theirs = $directs ? DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')
            ->join('centres as ce', 'ce.id', '=', 'f.centre_id')->where('ce.agency_id', $agencyId)
            ->whereIn('f.centre_id', $directs)->where(fn ($q) => Live::children($q, 'c'))
            ->pluck('c.id')->map(fn ($v) => (int) $v)->all() : [];

        return array_values(array_unique(array_merge(self::liveChildIds($agencyId, $user), $theirs)));
    }
}
