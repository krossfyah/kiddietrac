<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Support\AgencyRole;
use App\Support\Audit;
use App\Support\CentreScope;
use App\Support\HrLeave;
use App\Support\HrTalent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * KIDDIETRAC HR, PHASE 2 (2026-11-28): the one access rule for hiring, onboarding, performance and courses, the same
 * as HrController's (Phase 1). Always inside the ACTIVE agency; fails closed.
 *  - agency admin (and a platform admin working in the agency): every staff member of the agency;
 *  - centre director: the staff placed at their own centres (CentreScope -> HrLeave::staffIds);
 *  - a person's own records: their onboarding, reviews, goals, one-to-ones and courses;
 *  - a person's file manager (hr_employee_files.manager_user_id) sees that person's performance records too.
 * Nobody manages their own records: a manager never reviews or adjusts themselves.
 */
trait HrAccess
{
    use ResolvesCentreContext;

    protected function hrAgency(Request $request): int
    {
        $aid = (int) ($this->resolveAgencyId($request) ?: 0);
        abort_unless($aid > 0, 400, 'Select an agency first.');
        abort_unless(HrLeave::on($aid), 403, 'HR is not included in your agency\'s plan.');

        return $aid;
    }

    protected function hrIsAdmin(Request $request): bool
    {
        return (bool) array_intersect(AgencyRole::names($request), ['agency_admin', 'platform_admin']);
    }

    protected function hrIsManager(Request $request): bool
    {
        return $this->hrIsAdmin($request) || in_array('centre_director', AgencyRole::names($request), true);
    }

    protected function hrNeedManager(Request $request): void
    {
        abort_unless($this->hrIsManager($request), 403, 'Directors and admins only.');
    }

    protected function hrNeedAdmin(Request $request): void
    {
        abort_unless($this->hrIsAdmin($request), 403, 'Only an agency admin can change this.');
    }

    /** The staff this caller manages (admin: agency, director: own centres). @return int[] */
    protected function hrPeople(Request $request, int $aid): array
    {
        if (! $this->hrIsManager($request)) {
            return [];
        }

        return HrLeave::staffIds($aid, CentreScope::ids($request, $aid));
    }

    protected function hrNeedPerson(Request $request, int $aid, int $userId): void
    {
        abort_unless(in_array($userId, $this->hrPeople($request, $aid), true), 404, 'That person was not found.');
    }

    /** May this caller see this person's HR records? Themselves, their file manager, or a manager of them. */
    protected function hrCanSee(Request $request, int $aid, int $userId): bool
    {
        $me = (int) $request->user()->id;
        if ($userId === $me) {
            return in_array($me, HrLeave::staffIds($aid), true);
        }
        if (in_array($userId, $this->hrPeople($request, $aid), true)) {
            return true;
        }
        $mgr = (int) DB::table('hr_employee_files')->where('agency_id', $aid)->where('user_id', $userId)->value('manager_user_id');

        return $mgr === $me && in_array($userId, HrLeave::staffIds($aid), true);
    }

    protected function hrNeedSee(Request $request, int $aid, int $userId): void
    {
        abort_unless($this->hrCanSee($request, $aid, $userId), 404, 'That person was not found.');
    }

    protected function hrName(?object $u): string
    {
        return $u ? trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) : '';
    }

    protected function hrNames(array $ids): array
    {
        return DB::table('users')->whereIn('id', $ids ?: [0])->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($u) => [(int) $u->id => $this->hrName($u)])->all();
    }

    protected function hrNote(int $aid, string $action, string $type, int $id, array $payload = []): void
    {
        try {
            Audit::write(['user_id' => optional(request()->user())->id, 'agency_id' => $aid, 'action' => $action,
                'entity_type' => $type, 'entity_id' => $id, 'payload' => json_encode($payload)]);
        } catch (\Throwable $e) {
        }
    }

    /** A manager who may be named on someone's records: one of the agency's own people, never a super admin. */
    protected function hrManagerChoices(int $aid, int $forUser): array
    {
        $out = HrTalent::managerIds($aid);
        $out = array_values(array_diff($out, [$forUser]));

        return DB::table('users')->whereIn('id', $out ?: [0])->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
            ->map(fn ($m) => ['id' => (int) $m->id, 'name' => $this->hrName($m)])->all();
    }
}
