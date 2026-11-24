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
        // 1. Named beats everything, in both directions - unless the form says its named
        //    people come IN ADDITION to its audience (see extendsAudience()).
        if ($namedUserIds) {
            if (in_array($userId, array_map('intval', $namedUserIds), true)) {
                return true;
            }
            if (! self::extendsAudience($form)) {
                return false;
            }
        }

        $aud = self::audiencesOf($form);

        // 3. Nobody named and no audience: closed.
        if (! $aud) {
            return false;
        }

        // 2. By role - and a form tied to one centre (a director's, 2026-11-13) only to that centre's people.
        if (! array_intersect($aud, $roles)) {
            return false;
        }

        return self::atFormCentre($form, $userId);
    }

    /* Memo for one request/job tick. Expires after 30 s so a long-running queue worker never trusts an old
       answer about who works where. */
    private static array $centreOfForm = [];
    private static array $centrePeople = [];
    private static int $memoAt = 0;

    private static function fresh(): void
    {
        if (time() - self::$memoAt > 30) {
            self::$centreOfForm = [];
            self::$centrePeople = [];
            self::$memoAt = time();
        }
    }

    /** The centre a form belongs to; null = agency-wide. Looked up when the row was selected without it. */
    public static function centreOf(object $form): ?int
    {
        if (property_exists($form, 'centre_id')) {
            return $form->centre_id ? (int) $form->centre_id : null;
        }
        $id = (int) ($form->id ?? 0);
        if (! $id) {
            return null;
        }
        self::fresh();
        if (! array_key_exists($id, self::$centreOfForm)) {
            $c = DB::table('managed_forms')->where('id', $id)->value('centre_id');
            self::$centreOfForm[$id] = $c ? (int) $c : null;
        }

        return self::$centreOfForm[$id];
    }

    /**
     * Is this person at the form's centre? Always true for an agency-wide form. Otherwise the centre's people
     * (CentreScope::peopleIds) and the agency admins of the centre's agency - a centre form is still the
     * agency's business.
     */
    public static function atFormCentre(object $form, int $userId): bool
    {
        $centreId = self::centreOf($form);
        if (! $centreId) {
            return true;
        }
        self::fresh();
        if (! isset(self::$centrePeople[$centreId])) {
            $people = CentreScope::peopleIds([$centreId]);
            $agencyId = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
            $admins = $agencyId ? DB::table('role_assignments')->where('active', 1)->where('role', 'agency_admin')
                ->where('agency_id', $agencyId)->pluck('user_id')->map(fn ($v) => (int) $v)->all() : [];
            self::$centrePeople[$centreId] = array_flip(array_merge($people, $admins));
        }

        return isset(self::$centrePeople[$centreId][$userId]);
    }

    /**
     * NAMED PEOPLE AS WELL AS THE AUDIENCE, by choice, per form (2026-10-20).
     *
     * Rule 1 stays the default: naming people means only them. But it bit the other way on
     * 2026-10-20: an admin bulk-sent iLearn's parent paperwork to one parent, Daily Sleep
     * Chart and Daily Supervision Check (audience: educator) were in the batch, and naming
     * that one parent made both staff forms hers alone - every provider's save came back
     * 403 mid-draft. Anthony: keep her on them AND give the educators back. A form with
     * managed_forms.named_extends_audience = 1 is open to its audience PLUS the named people.
     * Bulk assign now asks which one the admin means before it narrows a role-wide form.
     */
    public static function extendsAudience(object $form): bool
    {
        return ! empty($form->named_extends_audience);
    }

    /** The audience list off a form row, whatever shape it was stored in. */
    /**
     * IS THIS SIGN-OFF FAMILY PAPERWORK, OR STAFF PAPERWORK? (2026-11-24)
     *
     * A person can be staff AND a parent in the same agency (an educator whose own
     * children attend). Everything downstream of a signature used to answer "which
     * family is this?" with "whichever family the signer is a guardian of" - so an
     * educator's Daily Sleep Charts and Supervision Checks were filed onto her own
     * family's record and the office email said "Family: <her family>" on every one.
     * The signer being a guardian is a fact about the PERSON; whether the paper is
     * family paperwork is a fact about the FORM.
     *
     * Decided by the form's audience, server-side:
     *   - family roles only (guardian)          -> family paperwork
     *   - staff roles only                      -> staff paperwork, never a family's
     *   - both, or none (named people only)     -> family paperwork only when the signer
     *     holds no live staff role in that agency. A dual-role signer of an ambiguous
     *     form is treated as staff: failing closed means her work never lands on her
     *     family's record, where her co-parent and her children's centre can read it.
     *
     * @param  object  $form  needs ->audiences
     */
    public static function isFamilyPaperwork(object $form, int $userId, int $agencyId): bool
    {
        $aud = self::audiencesOf($form);
        $fam = (bool) array_intersect($aud, ['guardian', 'parent', 'parents', 'family']);
        $staff = (bool) array_intersect($aud, \App\Support\Visibility::STAFF_ROLES);
        if ($fam && ! $staff) {
            return true;
        }
        if ($staff && ! $fam) {
            return false;
        }
        return ! self::isStaffIn($userId, $agencyId);
    }

    /** Does this person hold a live staff role in this agency (agency-wide or at one of its centres)? */
    public static function isStaffIn(int $userId, int $agencyId): bool
    {
        if ($userId <= 0 || $agencyId <= 0) {
            return false;
        }
        /* Memoised for 30 seconds, not for the life of the process: a queue worker runs for
           hours, and a role granted or removed must be seen on the next job. */
        static $memo = [];
        $k = $userId . ':' . $agencyId;
        if (! isset($memo[$k]) || $memo[$k][1] < time() - 30) {
            $memo[$k] = [DB::table('role_assignments as ra')
                ->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
                ->where('ra.user_id', $userId)->where('ra.active', 1)
                ->whereIn('ra.role', \App\Support\Visibility::STAFF_ROLES)
                ->where(fn ($q) => $q->where('ra.agency_id', $agencyId)->orWhere('c.agency_id', $agencyId))
                ->exists(), time()];
        }
        return $memo[$k][0];
    }

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
