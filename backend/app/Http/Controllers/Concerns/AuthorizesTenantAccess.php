<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * The single place an ownership question is answered.
 *
 * Guards were previously spelled authorizeFamily / authorizeChild / canAccessChild /
 * canSeeChild / providerCanAccess / assertAccess / assertParticipant / isGuardianOf —
 * all correct, none alike. With eight spellings, a method that forgot to guard looks
 * exactly like one that did, to a reviewer and to any scanner. A cross-agency leak sat
 * in AdminController::duplicateCheck() for that reason: the child and family branches
 * were scoped, the user branch was not, and nothing made the difference visible.
 *
 * Two shapes only:
 *   assertX()  aborts 403 and returns void  — use when the endpoint requires access
 *   mayX()     returns bool                 — use when the caller branches on it
 *
 * Anything else is a smell. If a check does not fit these, it is probably a scoping
 * question (which rows may I see) rather than an ownership question (may I see THIS
 * row), and belongs with the agency/centre helpers instead.
 */
trait AuthorizesTenantAccess
{
    /** Guardians of a family, as ints. */
    private function guardianIdsOfFamily(int $familyId): array
    {
        return DB::table('guardians')->where('family_id', $familyId)
            ->whereNotNull('user_id')->pluck('user_id')
            ->map(fn ($v) => (int) $v)->all();
    }

    /** Centre ids belonging to an agency. */
    private function centreIdsOfAgency(int $agencyId): array
    {
        return DB::table('centres')->where('agency_id', $agencyId)->pluck('id')
            ->map(fn ($v) => (int) $v)->all();
    }

    /** Is this user a guardian on that family? */
    public function mayAccessFamily(int $userId, int $familyId): bool
    {
        if (in_array($userId, $this->guardianIdsOfFamily($familyId), true)) {
            return true;
        }

        // Staff reach a family through the centre it belongs to.
        $centreId = (int) DB::table('families')->where('id', $familyId)->value('centre_id');
        return $centreId > 0 && $this->mayAccessCentre($userId, $centreId);
    }

    /** Is this user a guardian of that child, or staff at its centre? */
    public function mayAccessChild(int $userId, int $childId): bool
    {
        $familyId = (int) DB::table('children')->where('id', $childId)->value('family_id');
        if ($familyId > 0 && $this->mayAccessFamily($userId, $familyId)) {
            return true;
        }

        /* ...or through their PLACEMENT.
           families.centre_id is the family's single anchor, and a child is not always
           with it: a week can be split across providers, and one sibling can move while
           another stays. Amna could not check Aydan Rappitt in — the API answered
           "Child not found" — while he was enrolled in her own room, because his family
           is anchored to another provider.

           Scoped to an OPEN enrolment in a room at a centre this user can already reach,
           so it grants nothing to anybody who could not already reach that room; it just
           stops a correct placement being invisible to the person delivering the care.
           (2026-08-27) */
        $centreIds = DB::table('enrollments as e')
            ->join('rooms as r', 'r.id', '=', 'e.room_id')
            ->where('e.child_id', $childId)
            ->whereNull('e.end_date')
            ->distinct()
            ->pluck('r.centre_id');

        foreach ($centreIds as $centreId) {
            if ($centreId && $this->mayAccessCentre($userId, (int) $centreId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this user staff at that centre, or an admin of the agency owning it?
     *
     * A platform_admin is deliberately NOT waved through. Their access is scoped to
     * the agency they have switched into (X-Active-Agency-Id) — v22p96 closed exactly
     * this hole, where an unconditional platform pass leaked care logs, milestones and
     * portfolios across every tenant for a switched super-admin. This trait has to be
     * at least as strict as the strictest guard it replaces; otherwise converging on
     * it lowers the floor while looking like a tidy-up.
     */
    public function mayAccessCentre(int $userId, int $centreId): bool
    {
        $agencyId = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
        if ($agencyId <= 0) {
            return false;
        }

        /* ORDINARY MEMBERSHIP FIRST. A person can hold platform_admin AND a normal
           role — user #1 is agency_admin of agency 2 as well as a platform admin.
           Checking the platform rule first made the header mandatory for them and
           refused access to their own agency's families; a differential test over
           13,238 real pairs caught it as 82 lockouts. */
        $member = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)
            ->where(function ($q) use ($centreId, $agencyId) {
                $q->where('centre_id', $centreId)
                  ->orWhere(function ($w) use ($agencyId) {
                      $w->where('role', 'agency_admin')->where('agency_id', $agencyId);
                  });
            })->exists();
        if ($member) {
            return true;
        }

        /* Otherwise a platform admin reaches only the agency they have switched into.
           v22p96/v22p98 closed exactly this: an unconditional platform pass leaked
           care logs and ledgers across every tenant. */
        $isPlatform = DB::table('role_assignments')->where('user_id', $userId)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform) {
            $active = (int) request()->header('X-Active-Agency-Id');

            return $active > 0 && $active === $agencyId;
        }

        return false;
    }

    /** Is this user a participant of that family conversation? */
    public function mayAccessConversation(int $userId, int $conversationId): bool
    {
        $conv = DB::table('conversations')->where('id', $conversationId)
            ->first(['family_id', 'centre_id']);
        if (! $conv) {
            return false;
        }
        if (in_array($userId, $this->guardianIdsOfFamily((int) $conv->family_id), true)) {
            return true;
        }

        return $this->mayAccessCentre($userId, (int) $conv->centre_id);
    }

    /** Is this user a participant of that colleague thread? */
    public function mayAccessStaffThread(int $userId, int $threadId): bool
    {
        return DB::table('staff_thread_participants')
            ->where('thread_id', $threadId)->where('user_id', $userId)->exists();
    }

    // ── assertions ────────────────────────────────────────────────────────
    // Each aborts 403. Never 404 — "no such record" and "not yours" must not be
    // distinguishable from outside, or the response itself confirms existence.

    public function assertFamily(int $userId, int $familyId): void
    {
        abort_unless($this->mayAccessFamily($userId, $familyId), 403);
    }

    public function assertChild(int $userId, int $childId): void
    {
        abort_unless($this->mayAccessChild($userId, $childId), 403);
    }

    public function assertCentre(int $userId, int $centreId): void
    {
        abort_unless($this->mayAccessCentre($userId, $centreId), 403);
    }

    public function assertConversation(int $userId, int $conversationId): void
    {
        abort_unless($this->mayAccessConversation($userId, $conversationId), 403);
    }

    public function assertStaffThread(int $userId, int $threadId): void
    {
        abort_unless($this->mayAccessStaffThread($userId, $threadId), 403);
    }
}
