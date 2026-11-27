<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KIDDIETRAC HR PHASE 4, BATCH 6 (2026-11-27): WHO ELSE MAY DECIDE A TIME-OFF REQUEST. Table hr_approver_grants.
 *
 * Time off is decided by an agency admin or a director of the request's centre (TimeOffController::assertDecider).
 * The hr add-on adds two explicit, audited extras:
 *   - 'people': an agency admin lets a manager approve named people's time off (optionally only some leave types),
 *     even outside that manager's centres. Kept until an admin removes it.
 *   - 'cover': a director who is away names another manager, who gets THAT director's approvals (the requests of the
 *     centres they direct, plus their own 'people' grants) and the approval emails from starts_on to ends_on in the
 *     agency's days. It ends by itself (no job: "active" is worked out from today) or early when revoked.
 * Never more than the person covered could do: no chaining of covers, never anyone's own request, never the covered
 * director's own request (they could not decide it either), and a cover may close only a centre the covered director
 * directs. A 'people' grant never closes a centre (only that centre's director or an admin does).
 * Without the hr add-on the grants do nothing.
 */
final class HrApprovers
{
    public const MAX_COVER_DAYS = 92;

    private static function ready(): bool
    {
        static $ok = null;
        if ($ok === null) $ok = rescue(fn () => Schema::hasTable('hr_approver_grants'), false, false);

        return $ok;
    }

    private static function ints($json): ?array
    {
        if ($json === null) return null;
        $a = json_decode((string) $json, true);

        return is_array($a) ? array_values(array_unique(array_map('intval', $a))) : null;
    }

    /** Grants in force today (agency days). @return object[] with subjects/types decoded */
    public static function active(int $agencyId, ?int $approverId = null, ?string $kind = null): array
    {
        if (! self::ready() || ! HrLeave::on($agencyId)) return [];
        $today = AgencyTime::today($agencyId);
        $rows = DB::table('hr_approver_grants')->where('agency_id', $agencyId)->whereNull('revoked_at')
            ->when($approverId !== null, fn ($q) => $q->where('approver_user_id', $approverId))
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->get();
        // A grant to or for someone who is no longer one of the agency's managers does nothing.
        $managers = HrTalent::managerIds($agencyId);
        $out = [];
        foreach ($rows as $g) {
            if (! in_array((int) $g->approver_user_id, $managers, true)) continue;
            if ($g->kind === 'cover' && ! in_array((int) $g->covering_for_user_id, $managers, true)) continue;
            $g->subjects = self::ints($g->subject_user_ids) ?? [];
            $g->types = self::ints($g->leave_type_ids);
            $out[] = $g;
        }

        return $out;
    }

    /** The centres of this agency someone directs (centre_director rows on open centres). @return int[] */
    public static function directs(int $userId, int $agencyId): array
    {
        $in = AgencyRole::centreIds($agencyId);

        return DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)->where('role', 'centre_director')
            ->whereIn('centre_id', $in ?: [0])->pluck('centre_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /** The centres a request belongs to: its own, else every centre (or room's centre) the person works at. @return int[] */
    public static function requestCentres(object $row): array
    {
        $inAgency = AgencyRole::centreIds((int) $row->agency_id);
        if (! empty($row->centre_id)) return [(int) $row->centre_id];

        return DB::table('role_assignments')->whereIn('role', Visibility::STAFF_ROLES)->where('user_id', $row->user_id)
            ->where('active', true)->whereIn('centre_id', $inAgency ?: [0])->pluck('centre_id')
            ->merge(DB::table('educator_rooms as er')->join('rooms as r', 'r.id', '=', 'er.room_id')
                ->where('er.user_id', $row->user_id)->whereIn('r.centre_id', $inAgency ?: [0])->pluck('r.centre_id'))
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    private static function typeOf(object $row): ?int
    {
        if (property_exists($row, 'leave_type_id')) return $row->leave_type_id ? (int) $row->leave_type_id : null;
        $t = DB::table('time_off_requests')->where('id', $row->id ?? 0)->value('leave_type_id');

        return $t ? (int) $t : null;
    }

    private static function peopleCovers(object $g, object $row): bool
    {
        if (! in_array((int) $row->user_id, $g->subjects, true)) return false;
        if ($g->types === null) return true;
        $t = self::typeOf($row);

        return $t !== null && in_array($t, $g->types, true);
    }

    /**
     * May this user decide this request through a grant? null = no; ['kind' => 'people'|'cover', 'for' => covered
     * user id|null, 'grant' => id, 'close' => centres they may close]. The ordinary rule (admin / own centre's director)
     * is checked by the caller first; this is only the extra.
     */
    public static function via(int $userId, object $row): ?array
    {
        $aid = (int) $row->agency_id;
        if ($userId === (int) $row->user_id) return null;   // nobody approves their own
        $grants = self::active($aid, $userId);
        if (! $grants) return null;
        foreach ($grants as $g) {
            if ($g->kind === 'people' && self::peopleCovers($g, $row)) {
                return ['kind' => 'people', 'for' => null, 'grant' => (int) $g->id, 'close' => []];
            }
        }
        $centres = null;
        foreach ($grants as $g) {
            if ($g->kind !== 'cover') continue;
            $absent = (int) $g->covering_for_user_id;
            if ($absent === (int) $row->user_id) continue;   // the covered director's own request: they could not decide it
            $dirs = self::directs($absent, $aid);
            $centres ??= self::requestCentres($row);
            if (array_intersect($dirs, $centres)) {
                return ['kind' => 'cover', 'for' => $absent, 'grant' => (int) $g->id, 'close' => $dirs];
            }
            foreach (self::active($aid, $absent, 'people') as $pg) {
                if (self::peopleCovers($pg, $row)) return ['kind' => 'cover', 'for' => $absent, 'grant' => (int) $g->id, 'close' => []];
            }
        }

        return null;
    }

    /**
     * Who else is asked about a request (bell + email): 'people' grants for this person, and the covers of every
     * director who would be asked (their centres) or who holds a 'people' grant for them. Never the requester.
     * @return int[]
     */
    public static function extraApproverIds(int $agencyId, object $req): array
    {
        $all = self::active($agencyId);
        if (! $all) return [];
        $out = [];
        $centres = isset($req->centre_ids) ? array_map('intval', (array) $req->centre_ids) : self::requestCentres((object) (['agency_id' => $agencyId] + (array) $req));
        $peopleHolders = [];
        foreach ($all as $g) {
            if ($g->kind === 'people' && self::peopleCovers($g, $req)) {
                $out[] = (int) $g->approver_user_id;
                $peopleHolders[] = (int) $g->approver_user_id;
            }
        }
        foreach ($all as $g) {
            if ($g->kind !== 'cover') continue;
            $absent = (int) $g->covering_for_user_id;
            if ($absent === (int) $req->user_id) continue;
            if (in_array($absent, $peopleHolders, true) || array_intersect(self::directs($absent, $agencyId), $centres)) {
                $out[] = (int) $g->approver_user_id;
            }
        }

        return array_values(array_diff(array_unique($out), [(int) $req->user_id]));
    }

    /**
     * For the Team requests list: the extra people and centres this user may see through grants (the list then keeps
     * only rows via() allows). ['users' => int[], 'centres' => int[]] or null when there is nothing extra.
     */
    public static function listExtras(int $agencyId, int $userId): ?array
    {
        $grants = self::active($agencyId, $userId);
        if (! $grants) return null;
        $users = [];
        $centres = [];
        foreach ($grants as $g) {
            if ($g->kind === 'people') $users = array_merge($users, $g->subjects);
            if ($g->kind === 'cover') {
                $centres = array_merge($centres, self::directs((int) $g->covering_for_user_id, $agencyId));
                foreach (self::active($agencyId, (int) $g->covering_for_user_id, 'people') as $pg) $users = array_merge($users, $pg->subjects);
            }
        }

        return ['users' => array_values(array_unique($users)), 'centres' => array_values(array_unique($centres))];
    }

    /** A short line for the request list: "Covering for Elena" / "You approve for Sam". */
    public static function label(array $via): string
    {
        if ($via['kind'] === 'cover') {
            $n = DB::table('users')->where('id', $via['for'])->value('first_name');

            return 'Covering for ' . ($n ?: 'a director');
        }

        return 'You approve this person\'s time off';
    }
}
