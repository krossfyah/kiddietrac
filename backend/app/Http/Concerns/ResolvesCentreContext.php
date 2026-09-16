<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Shared helper for controllers: figure out which centre/agency the
 * current user belongs to, and authorize record access.
 *
 * Pulled out of every director/educator controller to avoid
 * duplicated SQL in v2.
 */
trait ResolvesCentreContext
{
    /**
     * Return the centre this user is associated with, or null.
     * Director / agency_admin: their assigned centre, or first centre
     * in their agency.
     * Educator: their assigned centre.
     */
    protected function resolveCentreId($user): ?int
    {
        if (! $user) {
            return null;
        }

        // SECURITY (v22p97): centre resolution is SCOPED to the ACTIVE agency
        // (X-Active-Agency-Id). Previously an agency_admin with no direct centre
        // fell through to "the first centre in their agency" while IGNORING the
        // header — so a super-admin (who is also the iLearn agency_admin) viewing
        // "Test Agency" still resolved to an iLearn centre and saw iLearn families
        // /dashboards. Now a centre is only ever resolved WITHIN the agency the
        // user has switched into.
        $agencyId = $this->activeAgencyForCentreScope($user);
        if (! $agencyId) {
            return null;
        }

        // A centre the user is directly assigned to, inside the active agency.
        $direct = DB::table('role_assignments as ra')
            ->join('centres as c', 'c.id', '=', 'ra.centre_id')
            ->where('ra.user_id', $user->id)
            ->where('ra.active', true)
            ->whereIn('ra.role', ['centre_director', 'agency_admin', 'educator'])
            ->where('c.agency_id', $agencyId)
            ->whereNull('c.deleted_at')
            ->orderBy('ra.centre_id')
            ->value('ra.centre_id');
        if ($direct) {
            return (int) $direct;
        }

        /* PUT IN A ROOM HERE, SO THIS IS HER CENTRE.

           An agency-attached role — home_visitor — is stored with centre_id NULL and is
           not in the list above, so a home visitor resolved to no centre at all and
           RoomController::bootstrap() answered 403 "No centre access". The roster and
           ratio routes were open to her; the screen that loads first was not.

           authorizeCentreAccess() already grants on exactly this basis. This is the same
           rule for the other guard, with the same tenant condition: the room must sit in
           a centre of the ACTIVE agency, so a stale educator_rooms row cannot carry
           somebody into an agency they have left. Only ever grants. */
        $viaRoom = DB::table('educator_rooms as er')
            ->join('rooms as r', 'r.id', '=', 'er.room_id')
            ->join('centres as c', 'c.id', '=', 'r.centre_id')
            ->where('er.user_id', $user->id)
            ->where('c.agency_id', $agencyId)
            ->whereNull('c.deleted_at')
            ->orderBy('c.id')
            ->value('c.id');
        if ($viaRoom) {
            return (int) $viaRoom;
        }

        // An agency_admin (or platform_admin) of the ACTIVE agency, with no direct
        // centre assignment, falls through to that agency's first centre. (Prefer
        // an 'active' centre but accept onboarding ones so a new agency still
        // resolves — e.g. Test Agency's only centre is in 'onboarding' status.)
        $isAdminOfActive = $this->isPlatformAdminUser($user)
            || DB::table('role_assignments')->where('user_id', $user->id)->where('active', true)
                ->where('role', 'agency_admin')->where('agency_id', $agencyId)->exists();
        if (! $isAdminOfActive) {
            return null;
        }

        $centre = DB::table('centres')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->value('id');

        return $centre ? (int) $centre : null;
    }

    /**
     * v22p97 — The agency the user is currently operating in, for centre scoping.
     * Honours X-Active-Agency-Id (validated). A platform_admin is scoped ONLY to
     * the agency they have explicitly switched into — it NEVER silently defaults
     * to the first agency (that default is exactly how a super-admin ended up
     * seeing iLearn data while "in" Test Agency).
     */
    private function activeAgencyForCentreScope($user): ?int
    {
        $activeId = (int) request()->header('X-Active-Agency-Id');
        if ($this->isPlatformAdminUser($user)) {
            return ($activeId && DB::table('agencies')->where('id', $activeId)->whereNull('deleted_at')->exists())
                ? $activeId : null;
        }
        if ($activeId && $this->userBelongsToAgency($user->id, $activeId)) {
            return $activeId;
        }
        $own = DB::table('role_assignments')
            ->where('user_id', $user->id)->where('active', true)
            ->whereIn('role', ['agency_admin', 'centre_director', 'educator', 'auditor'])
            ->whereNotNull('agency_id')->value('agency_id');
        if ($own) {
            return (int) $own;
        }
        $cid = DB::table('role_assignments')
            ->where('user_id', $user->id)->where('active', true)
            ->whereNotNull('centre_id')->value('centre_id');
        return $cid ? (int) DB::table('centres')->where('id', $cid)->value('agency_id') : null;
    }

    /**
     * Resolve the centre object itself.
     */
    protected function resolveCentre($user): ?object
    {
        $id = $this->resolveCentreId($user);

        return $id ? DB::table('centres')->where('id', $id)->first() : null;
    }

    /**
     * Confirm the user can access a record belonging to a centre.
     */
    protected function authorizeCentreAccess($user, int $centreId): bool
    {
        $has = DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->where('active', true)
            ->where(function ($q) use ($centreId) {
                $q->where('centre_id', $centreId)
                  ->orWhere(function ($q2) use ($centreId) {
                      $centre = DB::table('centres')->where('id', $centreId)->first();
                      if ($centre) {
                          $q2->where('agency_id', $centre->agency_id)
                             ->where('role', 'agency_admin');
                      }
                  });
            })
            ->exists();
        if ($has) return true;

        /* PUT IN A ROOM HERE, SO ALLOWED IN THIS CENTRE.

           An agency-attached role — home_visitor is the one that matters — is stored with
           centre_id NULL, so the check above finds nothing and refuses a centre the person
           was deliberately given rooms in. Lloydene King held nine room assignments and
           was 403'd on every roster.

           canAccessChildId() below already grants on exactly this basis, and says why:
           "It requires an EXPLICIT room assignment (somebody put this person in that
           room)". The same fact means the same thing one level up.

           TWO conditions, both required. The room assignment alone is not enough: the
           person must also hold an ACTIVE role in that centre's agency, so a stale
           educator_rooms row cannot outlive the role that justified it and carry somebody
           into an agency they have left. Only ever grants — every refusal above still
           refuses. */
        $centreAgencyId = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
        if ($centreAgencyId > 0) {
            $assignedHere = DB::table('educator_rooms as er')
                ->join('rooms as r', 'r.id', '=', 'er.room_id')
                ->where('er.user_id', $user->id)
                ->where('r.centre_id', $centreId)
                ->exists();

            if ($assignedHere) {
                $inAgency = DB::table('role_assignments')
                    ->where('user_id', $user->id)
                    ->where('active', true)
                    ->where('agency_id', $centreAgencyId)
                    ->exists();

                if ($inAgency) {
                    return true;
                }
            }
        }

        // v22p98: a platform_admin may access a centre in the agency they have
        // SWITCHED INTO (X-Active-Agency-Id). Without this, director-scoped screens
        // (certifications, medications, schedule, timesheets, …) 403 for a
        // super-admin viewing a tenant. Strictly scoped to the active agency —
        // never cross-tenant, and a header-less super-admin still gets nothing.
        if ($this->isPlatformAdminUser($user)) {
            $centreAgency = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
            $active = (int) request()->header('X-Active-Agency-Id');
            return $centreAgency > 0 && $centreAgency === $active;
        }
        return false;
    }

    /**
     * v22p94 — SECURE resolution of the active agency for the current user.
     * The `X-Active-Agency-Id` header is user-controlled, so it is only honoured
     * when the user is a platform_admin (may target any agency) OR actually holds
     * an active agency-level role_assignment for that agency. Otherwise we fall
     * back to the user's own agency. Never trust the header blindly — doing so
     * leaks one agency's data to another's admin.
     */
    protected function resolveAgencyId($request): ?int
    {
        $user = $request->user();
        if (! $user) return null;

        $isPlatform = DB::table('role_assignments')
            ->where('user_id', $user->id)->where('role', 'platform_admin')->where('active', true)->exists();

        $activeId = (int) $request->header('X-Active-Agency-Id');

        if ($isPlatform) {
            // SECURITY (v22p98): a platform_admin must EXPLICITLY select an agency —
            // never silently default to the first (iLearn). A missing/invalid header
            // returns null (caller treats as "no agency"), so a super-admin never
            // sees a real tenant's data without choosing it.
            return ($activeId && DB::table('agencies')->where('id', $activeId)->whereNull('deleted_at')->exists())
                ? $activeId : null;
        }

        // Non-platform: header only honoured if the user belongs to that agency.
        if ($activeId && $this->userBelongsToAgency($user->id, $activeId)) {
            return $activeId;
        }
        // Fall back to the user's own agency (via a direct agency role, or the
        // agency that owns their assigned centre).
        $own = DB::table('role_assignments')
            ->where('user_id', $user->id)->where('active', true)
            ->whereIn('role', ['agency_admin', 'centre_director', 'educator', 'auditor'])
            ->whereNotNull('agency_id')->value('agency_id');
        if ($own) return (int) $own;
        $centreId = $this->resolveCentreId($user);
        return $centreId ? (int) DB::table('centres')->where('id', $centreId)->value('agency_id') : null;
    }

    /** Does the user hold ANY active role tied to this agency (directly or via a centre in it)? */
    protected function userBelongsToAgency(int $userId, int $agencyId): bool
    {
        $direct = DB::table('role_assignments')
            ->where('user_id', $userId)->where('active', true)->where('agency_id', $agencyId)->exists();
        if ($direct) return true;
        return DB::table('role_assignments')
            ->where('role_assignments.user_id', $userId)->where('role_assignments.active', true)
            ->join('centres', 'centres.id', '=', 'role_assignments.centre_id')
            ->where('centres.agency_id', $agencyId)->exists();
    }

    /**
     * v22p94 — Can this user access this CHILD? True if they are a guardian of the
     * child's family, OR staff of the child's centre (educator/director), OR
     * agency_admin of the centre's agency, OR platform_admin. Use on every
     * endpoint that takes a child id in the URL/body.
     */
    /**
     * Which centres may this caller SEE?
     *
     * Delegates to App\Support\Visibility so the rule has ONE home — controllers
     * without this trait need the same answer, and a rule copied into two places is
     * a rule that will disagree with itself.
     *
     * FAILS CLOSED: [] means nothing. Always `?: [0]` in the whereIn.
     *
     * @return int[]
     */
    protected function visibleCentreIds($request): array
    {
        return \App\Support\Visibility::centreIds(
            (int) ($this->resolveAgencyId($request) ?: 0),
            $request->user()
        );
    }

    /**
     * Which children may this caller see? The centre rule, followed through.
     *
     * A child belongs to a family and a family belongs to a centre, so this is where
     * "which centres" becomes "which records" for every child-shaped table —
     * incidents, care logs, observations, media. Same fail-closed contract: [] means
     * nothing.
     *
     * @return int[]
     */
    protected function visibleChildIds($request): array
    {
        return \App\Support\Visibility::childIds(
            (int) ($this->resolveAgencyId($request) ?: 0),
            $request->user()
        );
    }

    protected function canAccessChildId($user, int $childId): bool
    {
        if (! $user || ! $childId) return false;
        $child = DB::table('children')->where('id', $childId)->first();
        if (! $child) return false;
        // Guardian of the child's family
        if (DB::table('guardians')->where('user_id', $user->id)->where('family_id', $child->family_id)->exists()) {
            return true;
        }
        $family = DB::table('families')->where('id', $child->family_id)->first();

        /* ROOM-SCOPED PEOPLE DO NOT GET THE CENTRE GRANT.

           The check below is centre-wide ("staff of the child's centre"), and it was the
           reason an educator correctly refused room 23's ROSTER could still open the file
           of a child sitting in room 23. Rosters were scoped by room, records by centre,
           and the two disagreed.

           Anyone whose access is defined by rooms is answered instead by SHARED CARE
           further down — an explicit assignment to the room the child is in today, with
           primary_room_id as the fallback when the schedule names no room, so a weekend
           still resolves.

           WHO IS ROOM-SCOPED:
             - a home visitor, always. Rooms are her entire basis; with none she should
               see nothing ("she must be able to access those rooms to perform her role").
             - an educator WHO HOLDS AT LEAST ONE ROOM.

           That last condition is the safety of the whole change. Shared care demands an
           EXPLICIT assignment, so an educator nobody has assigned yet has nothing to fall
           back on and would lose every child at once. Restricting only those who have
           been given rooms is exactly the rule assignedRoomIds() already applies to the
           roster: "no assignments yet -> not restricted".

           Directors and admins keep the centre grant — they are not room-scoped anywhere
           else, and scoping them would break the people whose job is the whole centre.

           Measured before shipping: iLearn 0 child-accesses removed (one room per centre,
           so the two scopes already coincided), Test Agency 48 removed (four rooms per
           centre) — which is the point. */
        $activeRoles = DB::table('role_assignments')
            ->where('user_id', $user->id)->where('active', true)
            ->pluck('role')->all();

        $privileged = (bool) array_intersect($activeRoles, ['centre_director', 'agency_admin', 'platform_admin']);
        $isHomeVisitor = in_array('home_visitor', $activeRoles, true);
        $isEducator = in_array('educator', $activeRoles, true);
        $holdsRooms = DB::table('educator_rooms')->where('user_id', $user->id)->exists();

        $roomScoped = ! $privileged && ($isHomeVisitor || ($isEducator && $holdsRooms));

        // Staff of the child's centre (or agency_admin of its agency)
        if (! $roomScoped
            && $family
            && $this->authorizeCentreAccess($user, (int) $family->centre_id)) {
            return true;
        }

        /* SHARED CARE — the child is with this person TODAY.

           A child can be with a different provider on different days: Mylah Rappit is
           with Cassandra Monday to Thursday and with Amna on Friday, which is what
           enrollments.schedule exists to say. The two checks above only know the
           family's HOME centre, so on a Friday they refused Amna for a child sitting
           in her own home — she tried to file an absence twice and was 403'd twice.

           Narrow on purpose. It requires an EXPLICIT room assignment (somebody put
           this person in that room), the child to be in that room TODAY, and the room
           to belong to the same agency as the child — a schedule must never reach
           across tenants. It only ever grants; every refusal above still refuses. */
        if (! $family) {
            return false;
        }
        try {
            /* EVERY room this child is enrolled in, not just today's.

               This began as a narrow addition on top of the centre grant, keyed on the
               room the child is in TODAY — right for an addition, wrong as the primary
               rule, which is what the room-scoping made it. A child's record must not
               blink in and out by day of week: u146 teaches room 39 and lost child 33
               (hers Monday to Thursday) every Sunday, because roomToday() returns null
               at the weekend and primary_room_id then names FRIDAY's provider instead.

               So: a room where this child holds an OPEN enrolment, which this person is
               explicitly assigned to. primary_room_id stays as an extra candidate — not
               a fallback — for a child whose enrolments are not written yet. */
            $childRooms = DB::table('enrollments')
                ->where('child_id', $childId)
                ->whereNull('end_date')
                ->pluck('room_id')
                ->map(fn ($r) => (int) $r)
                ->all();

            if (! empty($child->primary_room_id)) {
                $childRooms[] = (int) $child->primary_room_id;
            }
            $childRooms = array_values(array_unique(array_filter($childRooms)));
            if (! $childRooms) {
                return false;
            }

            // A room this person was actually put in.
            $matched = DB::table('educator_rooms')
                ->where('user_id', $user->id)
                ->whereIn('room_id', $childRooms)
                ->value('room_id');
            if (! $matched) {
                return false;
            }

            // The schedule must never reach across tenants.
            $roomAgency = DB::table('rooms as r')
                ->join('centres as ce', 'ce.id', '=', 'r.centre_id')
                ->where('r.id', $matched)->value('ce.agency_id');
            $childAgency = DB::table('centres')->where('id', $family->centre_id)->value('agency_id');

            return $roomAgency && $childAgency && (int) $roomAgency === (int) $childAgency;
        } catch (\Throwable $e) {
            return false;   // fail closed
        }
    }

    /** v22p94 — Can this user access this FAMILY? (guardian member, or staff of its centre.) */
    protected function canAccessFamilyId($user, int $familyId): bool
    {
        if (! $user || ! $familyId) return false;
        if (DB::table('guardians')->where('user_id', $user->id)->where('family_id', $familyId)->exists()) {
            return true;
        }
        $family = DB::table('families')->where('id', $familyId)->first();
        return $family ? $this->authorizeCentreAccess($user, (int) $family->centre_id) : false;
    }

    /** True if the user holds the platform_admin role. */
    protected function isPlatformAdminUser($user): bool
    {
        return $user && DB::table('role_assignments')
            ->where('user_id', $user->id)->where('role', 'platform_admin')->where('active', true)->exists();
    }

    /**
     * v22p96 — Agency-SCOPED platform-admin access to a child.
     * Returns true if the user can access the child normally (guardian or centre
     * staff), OR if they are a platform_admin AND the child belongs to the agency
     * they have currently switched INTO (resolveAgencyId honours X-Active-Agency-Id
     * for platform admins). This replaces the unconditional `isPlatformAdminUser`
     * short-circuits that let a super-admin read any child in any tenant regardless
     * of the active agency. Use on per-child endpoints instead of
     * `isPlatformAdminUser($u) || canAccessChildId(...)`.
     */
    protected function canAccessChildScoped($request, int $childId): bool
    {
        $user = $request->user();
        if ($this->canAccessChildId($user, $childId)) return true;
        if (! $this->isPlatformAdminUser($user)) return false;
        $child = DB::table('children')->where('id', $childId)->first();
        if (! $child) return false;
        $centreId = DB::table('families')->where('id', $child->family_id)->value('centre_id');
        if (! $centreId) return false;
        $childAgency = DB::table('centres')->where('id', $centreId)->value('agency_id');
        return $childAgency && (int) $childAgency === (int) $this->resolveAgencyId($request);
    }

    /** v22p96 — Agency-SCOPED platform-admin access to a family (see canAccessChildScoped). */
    protected function canAccessFamilyScoped($request, int $familyId): bool
    {
        $user = $request->user();
        if ($this->canAccessFamilyId($user, $familyId)) return true;
        if (! $this->isPlatformAdminUser($user)) return false;
        $centreId = DB::table('families')->where('id', $familyId)->value('centre_id');
        if (! $centreId) return false;
        $famAgency = DB::table('centres')->where('id', $centreId)->value('agency_id');
        return $famAgency && (int) $famAgency === (int) $this->resolveAgencyId($request);
    }
}
