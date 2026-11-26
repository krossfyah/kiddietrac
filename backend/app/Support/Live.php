<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * WHAT "STILL HERE" MEANS — one definition for every list, roster, picker and email.
 *
 * Anthony, 2026-10-09: "closed and archived providers and children are showing up in
 * emails and on roster ... we cannot ever have this happen in production". Closed
 * providers' Thanksgiving notices went out (one to a parent of a family left live on a
 * deleted centre), a withdrawn child was on an immunization reminder, and ratio status,
 * Safe Arrival, weekly attendance, the dashboard and the late-pickup picker listed
 * closed centres. Each reader wrote its own filter, and many simply had none: a centre
 * that is soft-deleted or status 'closed', and a child who is withdrawn or deleted, must
 * be dropped by the QUERY of anything that is about today.
 *
 * Use these for anything current (rosters, pickers, dashboards, reminders, digests).
 * History, ledgers, audit and "leaving" mail deliberately read closed records and do
 * not use them.
 *
 * Guarded nightly and on deploy by `kt:leak-sweep` and `kt:integrity-check`.
 */
final class Live
{
    /** Centre statuses that are NOT live. 'onboarding' is live (it is being set up). */
    public const CLOSED_CENTRE_STATUSES = ['closed', 'archived', 'inactive', 'deleted'];

    /** Constrain a query on `centres` (aliased $as) to open centres. */
    public static function centres($q, string $as = 'centres')
    {
        return $q->whereNull($as . '.deleted_at')
            ->where(fn ($w) => $w->whereNull($as . '.status')->orWhereNotIn($as . '.status', self::CLOSED_CENTRE_STATUSES));
    }

    /** Constrain a query on `children` (aliased $as) to children still with us (enrolled or waitlisted). */
    public static function children($q, string $as = 'children')
    {
        return $q->whereNull($as . '.deleted_at')
            ->where(fn ($w) => $w->whereNull($as . '.enrollment_status')->orWhere($as . '.enrollment_status', '!=', 'withdrawn'));
    }

    /** Constrain to children who are ENROLLED now (rosters, attendance, reminders). */
    public static function enrolledChildren($q, string $as = 'children')
    {
        return $q->whereNull($as . '.deleted_at')->where($as . '.enrollment_status', 'enrolled');
    }

    /** Constrain a query on `enrollments` (aliased $as) to enrolments open today (agency date). */
    public static function enrolments($q, string $as = 'enrollments', ?string $today = null)
    {
        $today = $today ?: \App\Support\AgencyTime::today();
        return $q->where(fn ($w) => $w->whereNull($as . '.end_date')->orWhere($as . '.end_date', '>=', $today));
    }

    /** Constrain a query on `families` (aliased $as) to families that have not left. */
    public static function families($q, string $as = 'families')
    {
        return $q->whereNull($as . '.deleted_at')
            ->where(fn ($w) => $w->whereNull($as . '.departure_applied_at'));
    }

    /**
     * THE EXPLICIT ARCHIVED PATH (2026-11-26, the agency admin's "Archived" report cards). The complement of
     * children() + a live family + centres(): rows whose child is withdrawn, whose family was de-enrolled
     * (soft-deleted), or whose centre is closed or deleted. Only for screens that deliberately show what has left,
     * behind an admin-only guard; never for anything about today. A deleted child is gone, not archived: never here.
     * $q joins children ($child), families ($family) and centres ($centre).
     */
    public static function archived($q, string $child = 'children', string $family = 'families', string $centre = 'centres')
    {
        return $q->whereNull($child . '.deleted_at')->where(function ($w) use ($child, $family, $centre) {
            $w->where($child . '.enrollment_status', 'withdrawn')
                ->orWhereNotNull($family . '.deleted_at')
                ->orWhereNotNull($centre . '.deleted_at')
                ->orWhereIn($centre . '.status', self::CLOSED_CENTRE_STATUSES);
        });
    }

    /**
     * Why one record is not live: 'centre_closed', 'child_withdrawn', or null while it is live. The same rule as
     * archived() row by row, failing closed: a missing or deleted child, family or centre counts as archived.
     */
    public static function archivedReason(?object $child, ?object $family, ?object $centre): ?string
    {
        if (! $centre || ! empty($centre->deleted_at) || in_array((string) ($centre->status ?? ''), self::CLOSED_CENTRE_STATUSES, true)) {
            return 'centre_closed';
        }
        if (! $child || ! $family || ! empty($child->deleted_at) || ! empty($family->deleted_at)
            || (string) ($child->enrollment_status ?? '') === 'withdrawn') {
            return 'child_withdrawn';
        }
        return null;
    }

    /** Open centre ids for an agency. */
    public static function centreIds(int $agencyId): array
    {
        return self::centres(DB::table('centres'))->where('agency_id', $agencyId)
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** Is this one centre open? */
    public static function centreOpen(?int $centreId): bool
    {
        return $centreId ? self::centres(DB::table('centres'))->where('id', $centreId)->exists() : false;
    }

    /** Keep only the open centres out of a list of ids (order kept). */
    public static function onlyOpenCentres(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
        if (! $ids) return [];
        $open = array_flip(self::centres(DB::table('centres'))->whereIn('id', $ids)->pluck('id')->map(fn ($v) => (int) $v)->all());
        return array_values(array_filter($ids, fn ($id) => isset($open[$id])));
    }
}
