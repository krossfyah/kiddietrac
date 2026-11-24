<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ResellerAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * A RESELLER WORKS ONLY INSIDE ITS OWN AGENCIES (2026-10-21).
 *
 * resolveAgencyId() quietly falls back to a person's own agency when the header names
 * one they do not belong to. For a reseller that would still be one of theirs - but the
 * rule asked for is FAIL CLOSED: naming an agency they do not own is refused outright,
 * so a tampered or stale header can never be mistaken for access.
 *
 * Only for reseller people (and never platform admins, who may enter any agency). An
 * agency they belong to in their own right (a genuine role there) is not refused.
 */
final class ResellerAgencyGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            // Group middleware runs before auth:sanctum: $request->user() is the web guard here and
            // was empty for every app token, so this guard never fired (found 2026-11-05).
            $user = \Illuminate\Support\Facades\Auth::guard('sanctum')->user();
            $header = (int) $request->header('X-Active-Agency-Id');
            if ($user && $header > 0 && ResellerAccess::isReseller((int) $user->id)) {
                $isPlatform = DB::table('role_assignments')->where('user_id', $user->id)
                    ->where('role', 'platform_admin')->where('active', true)->exists();
                if (! $isPlatform && ! ResellerAccess::owns((int) $user->id, $header)) {
                    $genuine = DB::table('role_assignments as ra')
                        ->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
                        ->where('ra.user_id', $user->id)->where('ra.active', true)->whereNull('ra.granted_by_reseller_id')
                        ->where('ra.role', '!=', 'reseller_admin')
                        ->where(function ($q) use ($header) { $q->where('ra.agency_id', $header)->orWhere('c.agency_id', $header); })
                        ->exists();
                    if (! $genuine) {
                        return response()->json([
                            'message' => 'That agency is not one of yours.',
                            'code' => 'reseller_agency_not_owned',
                        ], 403);
                    }
                }
            }
        } catch (\Throwable $e) {
            // A failure to check is a failure to allow: refuse rather than guess.
            $u = \Illuminate\Support\Facades\Auth::guard('sanctum')->user();
            if ((int) $request->header('X-Active-Agency-Id') > 0 && $u
                && ResellerAccess::isReseller((int) $u->id)) {
                return response()->json(['message' => 'Could not confirm access to that agency.'], 403);
            }
        }

        return $next($request);
    }
}
