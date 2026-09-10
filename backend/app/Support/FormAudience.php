<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * WHO A MANAGED FORM IS FOR. One rule, one place.
 *
 * There were five copies of this decision — ManagedFormController twice,
 * EducatorSelfController twice and SignedFormController — and they did not agree
 * with each other. Two treated a named recipient as ADDITIONAL to the role
 * audience, and two ignored named recipients completely and read a form with no
 * audience as "everybody". A rule that exists five times is a rule the product
 * does not actually have.
 *
 * What it cost (found 2026-09-10, reported by the agency owner): iLearn's
 * "Infant Feeding Plan" was assigned to one parent and left with its audience set
 * to `guardian`. Because named recipients only ever ADDED, the form stayed open to
 * every guardian in the agency — 99 people — and because an educator who is also a
 * parent at the centre holds a guardian role, it appeared in her STAFF checklist
 * next to the daily sleep charts. Natasha Satnarine had a draft on it. A form
 * intended for one family was being filled in by a member of staff.
 *
 * THE RULE, and it is deliberately narrowing:
 *
 *   1. named recipients on the form  ->  ONLY those people. The audience stops
 *      being a door. Picking people in Forms Manager is how you say "these ones",
 *      and it has to mean it.
 *   2. otherwise, a role audience    ->  holders of those roles in the agency.
 *   3. neither                       ->  NOBODY.
 *
 * Rule 3 is the reversal of what two of the five copies did. "No audience means
 * everyone" is a fail-open default on a table that holds signed parental consent,
 * feeding plans and medical instructions; the failure is silent and the exposure
 * is the whole agency. A form nobody can see is a support call. A form everybody
 * can see is an incident. `store()` and `update()` already refuse to save a form
 * with neither, so rule 3 should be unreachable — it is here because "should be
 * unreachable" is not a guarantee.
 */
final class FormAudience
{
    /**
     * The roles that decide what this person is shown.
     *
     * `guardians` is consulted as well as `role_assignments` because a parent is
     * often only ever recorded as a guardian of a family. Staff checklists pass
     * their own list instead — see staffRolesOf().
     *
     * @return string[]
     */
    public static function rolesOf(int $userId): array
    {
        $roles = DB::table('role_assignments')->where('user_id', $userId)
            ->where('active', 1)->pluck('role')->all();

        if (DB::table('guardians')->where('user_id', $userId)->exists()) {
            $roles[] = 'guardian';
        }

        return array_values(array_unique(array_map('strval', $roles)));
    }

    /**
     * The same, minus `guardian` — for a STAFF checklist.
     *
     * "What must this educator complete today" is a question about her shift, not
     * about her own children. A member of staff who is also a parent at the centre
     * still gets her family's forms in the parent portal, where they belong; what
     * she should not get is a parent form sitting in her daily task list looking
     * like something the centre is asking her to fill in.
     *
     * @return string[]
     */
    public static function staffRolesOf(int $userId): array
    {
        return array_values(array_filter(
            self::rolesOf($userId),
            fn ($r) => $r !== 'guardian'
        ));
    }

    /**
     * THE DECISION. Pure — hand it the facts and it does no queries of its own, so
     * a list can gather them once instead of once per row.
     *
     * @param  object    $form            needs ->id and ->audiences
     * @param  string[]  $roles           the viewer's roles
     * @param  int[]     $namedUserIds    user ids named on THIS form (empty = none)
     */
    public static function allows(object $form, array $roles, array $namedUserIds, int $userId): bool
    {
        // 1. Named beats everything, in both directions.
        if ($namedUserIds) {
            return in_array($userId, array_map('intval', $namedUserIds), true);
        }

        $aud = self::audiencesOf($form);

        // 3. Nobody named and no audience: closed.
        if (! $aud) {
            return false;
        }

        // 2. By role.
        return (bool) array_intersect($aud, $roles);
    }

    /** The audience list off a form row, whatever shape it was stored in. */
    public static function audiencesOf(object $form): array
    {
        $raw = $form->audiences ?? null;
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw)));
        }
        $decoded = $raw ? json_decode((string) $raw, true) : null;

        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    /**
     * Named recipients for a set of forms, in one query.
     *
     * @param  int[] $formIds
     * @return array<int, int[]>  form id => user ids
     */
    public static function namedMap(array $formIds): array
    {
        if (! $formIds) {
            return [];
        }

        return DB::table('managed_form_recipients')
            ->whereIn('managed_form_id', $formIds)
            ->get(['managed_form_id', 'user_id'])
            ->groupBy('managed_form_id')
            ->map(fn ($rows) => $rows->pluck('user_id')->map(fn ($v) => (int) $v)->all())
            ->all();
    }

    /**
     * Convenience for a single form when there is no list to batch with.
     *
     * @param  string[]|null $roles  pass staffRolesOf() for a staff checklist
     */
    public static function canUse(object $form, int $userId, ?array $roles = null): bool
    {
        $named = DB::table('managed_form_recipients')
            ->where('managed_form_id', $form->id)->pluck('user_id')->map(fn ($v) => (int) $v)->all();

        return self::allows($form, $roles ?? self::rolesOf($userId), $named, $userId);
    }

    /**
     * Filter a collection of form rows down to the ones this person may use.
     *
     * @param  iterable       $forms
     * @param  string[]|null  $roles
     * @return array          the rows that survive, re-indexed
     */
    public static function filter(iterable $forms, int $userId, ?array $roles = null): array
    {
        $rows = is_array($forms) ? $forms : iterator_to_array($forms);
        $ids = array_map(fn ($f) => (int) $f->id, $rows);
        $named = self::namedMap($ids);
        $roles = $roles ?? self::rolesOf($userId);

        return array_values(array_filter(
            $rows,
            fn ($f) => self::allows($f, $roles, $named[(int) $f->id] ?? [], $userId)
        ));
    }
}
