<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * THE AGENCY HEADER ALWAYS NAMES ONE OF YOURS (2026-11-05, data-leakage sweep).
 *
 * X-Active-Agency-Id is typed by the client. AgencyRole::activeAgency() and resolveAgencyId()
 * check it, but dozens of controllers read it raw (request()->header(...)) and then act on that
 * agency: staff offboarding, feedback release, document workflows, a payment-provider test. Any
 * signed-in person could name another tenant's id and those paths obeyed. And when the header was
 * missing or foreign, AgencyRole stopped narrowing roles altogether ("roles held anywhere").
 *
 * So before anything reads it, the header is made true: for a person who is not a platform admin,
 * a header naming an agency they do not belong to - or no header - is replaced by an agency they
 * DO belong to (the one they last worked in, else the lowest), and removed when they belong to
 * none. Every raw reader downstream then sees an agency the person is really in, and the role
 * helpers always narrow. Platform admins are untouched: entering any agency is their job, and
 * their helpers already scope to the agency they switched into. Resellers are left to
 * ResellerAgencyGuard, whose rule is to REFUSE an agency they do not own, not to redirect.
 *
 * Measured before shipping: no person outside the platform team belongs to more than one agency,
 * so for every real account this only ever replaces a wrong or missing header with their own.
 */
final class NormalizeActiveAgency
{
    private const H = 'X-Active-Agency-Id';

    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Group middleware runs before auth:sanctum, so $request->user() is the web guard
            // (empty for app tokens) - ask the token guard, as EnforcePlanFeatures does.
            $user = Auth::guard('sanctum')->user();
            if ($user) $this->normalise($request, (int) $user->id, (int) ($user->last_agency_id ?? 0));
        } catch (\Throwable $e) {
            // A failure to work out membership must not leave a foreign header in place.
            if ($request->headers->has(self::H) && Auth::guard('sanctum')->check()
                && ! $this->isPlatform((int) Auth::guard('sanctum')->id())) {
                $request->headers->remove(self::H);
            }
            Log::warning('kt.agency_header.check_failed', ['error' => $e->getMessage()]);
        }

        return $next($request);
    }

    private function normalise(Request $request, int $userId, int $last): void
    {
        $rows = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)
            ->get(['role', 'agency_id', 'centre_id']);
        foreach ($rows as $r) {
            if ($r->role === 'platform_admin' || $r->role === 'reseller_admin') return;
        }

        $member = [];
        $centreIds = [];
        foreach ($rows as $r) {
            if ($r->agency_id) $member[(int) $r->agency_id] = true;
            elseif ($r->centre_id) $centreIds[] = (int) $r->centre_id;
        }
        if ($centreIds) {
            foreach (DB::table('centres')->whereIn('id', $centreIds)->pluck('agency_id') as $a) {
                if ($a) $member[(int) $a] = true;
            }
        }
        // A parent belongs through a family at one of the agency's centres.
        foreach (DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('g.user_id', $userId)->whereNull('f.deleted_at')->pluck('c.agency_id') as $a) {
            if ($a) $member[(int) $a] = true;
        }

        $sent = (int) $request->header(self::H);
        if ($sent > 0 && isset($member[$sent])) return;

        $use = isset($member[$last]) ? $last : ($member ? min(array_keys($member)) : 0);
        if ($use > 0) $request->headers->set(self::H, (string) $use);
        else $request->headers->remove(self::H);
        // Anything computed from the old value this request must be recomputed.
        $request->attributes->remove('kt.agency_role.active');

        if ($sent > 0) {
            Log::warning('kt.agency_header.foreign', ['user_id' => $userId, 'sent' => $sent, 'used' => $use ?: null,
                'path' => $request->path(), 'method' => $request->method()]);
        }
    }

    private function isPlatform(int $userId): bool
    {
        return DB::table('role_assignments')->where('user_id', $userId)->where('role', 'platform_admin')
            ->where('active', true)->exists();
    }
}
