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

        $direct = DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->where('active', true)
            ->whereIn('role', ['centre_director', 'agency_admin', 'educator'])
            ->whereNotNull('centre_id')
            ->first();

        if ($direct) {
            return (int) $direct->centre_id;
        }

        // Agency admins without a direct centre assignment fall through to
        // the first active centre in their agency
        $agency = DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->where('active', true)
            ->where('role', 'agency_admin')
            ->whereNotNull('agency_id')
            ->first();

        if (! $agency) {
            return null;
        }

        $centre = DB::table('centres')
            ->where('agency_id', $agency->agency_id)
            ->where('status', 'active')
            ->orderBy('id')
            ->first();

        return $centre ? (int) $centre->id : null;
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
        return DB::table('role_assignments')
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
    }
}
