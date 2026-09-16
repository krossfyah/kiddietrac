<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Who "all parents" or "all educators" actually means.
 *
 * Announcements already answered this question privately inside its own controller.
 * Messenger now needs the same answer, and two private copies of "which roles count as
 * staff" is precisely how they drift until one of them quietly stops including somebody.
 * One definition, used by both.
 *
 * Everything is resolved through the agency's own centres. A broadcast that reached
 * another tenant's families would be the worst possible bug in this system, so the
 * scoping is not optional and there is no "all agencies" mode.
 */
class Audience
{
    /**
     * Accounts that are switched OFF and must never be contacted.
     *
     * Deliberately NOT the same list as ParentDailySummaryCommand's, which also drops
     * `invited` and `not_invited`. That is right for a digest — there is no point
     * emailing somebody who cannot log in to act on it — and wrong here: measured on live
     * data, 27 of 44 parents at one agency are `not_invited`, meaning nobody has invited
     * them yet, not that they are disabled. Excluding them would send a closure notice to
     * 13 of 44 families.
     *
     * A deactivated account is NOT soft-deleted — the row is intact with this status —
     * so filtering on deleted_at alone lets it through. That is the bug this closes.
     */
    public const OFF_STATUSES = ['deactivated', 'suspended'];

    /** Apply the rule to any query that has joined `users` under an alias. */
    /** A service account is not a person — never an audience member. */
    public static function excludeService($query, string $alias = 'u')
    {
        return $query->where(function ($q) use ($alias) {
            $q->whereNull($alias.'.email')
              ->orWhere(function ($w) use ($alias) {
                  $w->where($alias.'.email', 'not like', '%integration+%')
                    ->where($alias.'.email', 'not like', 'noreply@%')
                    ->where($alias.'.email', 'not like', 'no-reply@%');
              });
        });
    }

    public static function excludeOff($query, string $alias = 'u')
    {
        return $query->whereNotIn($alias.'.status', self::OFF_STATUSES);
    }

    /** Staff audiences and the roles behind them. */
    public const ROLES = [
        'educators' => ['educator'],
        'admins' => ['agency_admin', 'centre_director'],
    ];

    public const KEYS = ['parents', 'educators', 'contractors', 'admins', 'staff', 'all'];

    /** Human labels, so the UI and the confirmation wording agree. */
    public static function label(string $key): string
    {
        return [
            'parents' => 'all parents',
            'educators' => 'all educators',
            'contractors' => 'all contractors',
            'admins' => 'all admins and directors',
            'staff' => 'all staff',
            'all' => 'everyone',
        ][$key] ?? $key;
    }

    /**
     * Guardian user ids for an agency, or for one centre within it.
     *
     * @param  int|null  $centreId  null = every centre in the agency
     */
    public static function parents(int $agencyId, ?int $centreId = null): array
    {
        $centreIds = self::centreIds($agencyId, $centreId);
        if (! $centreIds) {
            return [];
        }
        $familyIds = DB::table('families')->whereIn('centre_id', $centreIds)
            ->whereNull('deleted_at')->pluck('id');
        if ($familyIds->isEmpty()) {
            return [];
        }

        return self::excludeOff(
            DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
                ->whereIn('g.family_id', $familyIds)->whereNull('u.deleted_at')
        )->pluck('u.id')->unique()->map(fn ($v) => (int) $v)->values()->all();
    }

    /**
     * Staff user ids for one of the staff audiences.
     *
     * A centre-scoped broadcast reaches that centre's staff plus agency-wide staff who
     * carry no centre of their own — a director attached to nothing is still responsible
     * for the place.
     */
    public static function staff(string $audience, int $agencyId, ?int $centreId = null): array
    {
        $q = self::excludeOff(
            DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.agency_id', $agencyId)->where('ra.active', true)
                ->whereNull('u.deleted_at')
        );

        if ($audience === 'contractors') {
            // Not a role — there is none that means it — so it is a flag set per person.
            $q->where('u.is_contractor', 1);
        } elseif ($audience === 'staff') {
            $q->whereIn('ra.role', ['educator', 'centre_director', 'agency_admin', 'home_visitor']);
        } else {
            $roles = self::ROLES[$audience] ?? [];
            if (! $roles) {
                return [];
            }
            $q->whereIn('ra.role', $roles);
        }

        if ($centreId) {
            $q->where(fn ($w) => $w->where('ra.centre_id', $centreId)->orWhereNull('ra.centre_id'));
        }

        return $q->pluck('u.id')->unique()->map(fn ($v) => (int) $v)->values()->all();
    }

    /** Everyone an audience covers, deduplicated. */
    public static function resolve(string $audience, int $agencyId, ?int $centreId = null): array
    {
        if ($audience === 'parents') {
            return self::parents($agencyId, $centreId);
        }
        if ($audience === 'all') {
            return array_values(array_unique(array_merge(
                self::parents($agencyId, $centreId),
                self::staff('staff', $agencyId, $centreId),
                self::staff('contractors', $agencyId, $centreId),
            )));
        }

        return self::staff($audience, $agencyId, $centreId);
    }

    /** Centre ids in scope. */
    private static function centreIds(int $agencyId, ?int $centreId): array
    {
        $q = DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at');
        if ($centreId) {
            $q->where('id', $centreId);
        }

        return $q->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Family ids for a parent broadcast.
     *
     * Messenger threads hang off a FAMILY, not a person — two guardians share one
     * conversation — so a parent broadcast writes one message per family rather than one
     * per guardian, or a couple gets the same notice twice in the same thread.
     */
    public static function families(int $agencyId, ?int $centreId = null): array
    {
        $centreIds = self::centreIds($agencyId, $centreId);
        if (! $centreIds) {
            return [];
        }

        return DB::table('families')->whereIn('centre_id', $centreIds)->whereNull('deleted_at')
            ->get(['id', 'centre_id'])->map(fn ($f) => ['id' => (int) $f->id, 'centre_id' => (int) $f->centre_id])
            ->all();
    }
}
