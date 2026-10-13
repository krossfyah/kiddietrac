<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\TempGuardians;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Temporary (forms-only) parent accounts — see App\Support\TempGuardians.
 *
 *   GET    /admin/temp-guardians?q=          list / look up by name or email (admins, directors)
 *   DELETE /admin/temp-guardians/{user}      remove one (admins)
 *   GET    /admin/temp-guardians/settings    { remove_after_days }   (admins)
 *   POST   /admin/temp-guardians/settings
 */
class TempGuardianController extends Controller
{
    /** The agency this request acts for; directors may look up, only admins change things. */
    private function agencyId(Request $request, bool $adminOnly): int
    {
        $uid = (int) $request->user()->id;
        $roles = $adminOnly ? ['agency_admin'] : ['agency_admin', 'centre_director'];
        $isPlatform = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)->where('role', 'platform_admin')->exists();
        $header = (int) $request->header('X-Active-Agency-Id');
        if ($header) {
            if ($isPlatform) return $header;
            $direct = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
                ->whereIn('role', $roles)->where('agency_id', $header)->exists();
            // A director's role row may carry only a centre; the centre names the agency.
            $viaCentre = ! $adminOnly && DB::table('role_assignments as ra')->join('centres as c', 'c.id', '=', 'ra.centre_id')
                ->where('ra.user_id', $uid)->where('ra.active', true)->where('ra.role', 'centre_director')->where('c.agency_id', $header)->exists();
            if ($direct || $viaCentre) return $header;
        }
        $own = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)->whereIn('role', $roles)->whereNotNull('agency_id')->value('agency_id');
        if (! $own && ! $adminOnly) {
            $own = DB::table('role_assignments as ra')->join('centres as c', 'c.id', '=', 'ra.centre_id')
                ->where('ra.user_id', $uid)->where('ra.active', true)->where('ra.role', 'centre_director')->value('c.agency_id');
        }
        abort_unless($own, 403, $adminOnly ? 'Agency administrators only.' : 'Administrators and directors only.');

        return (int) $own;
    }

    public function index(Request $request): JsonResponse
    {
        $a = $this->agencyId($request, false);
        $q = (string) $request->query('q', '');
        $limit = $q !== '' ? 8 : 100;

        return response()->json([
            'temp_guardians' => TempGuardians::list($a, $q !== '' ? $q : null, $limit),
            'settings' => TempGuardians::settings($a),
        ]);
    }

    public function destroy(Request $request, int $user): JsonResponse
    {
        $a = $this->agencyId($request, true);
        if (! TempGuardians::remove($a, $user, (int) $request->user()->id, 'removed by ' . trim(($request->user()->first_name ?? '') . ' ' . ($request->user()->last_name ?? '')))) {
            return response()->json(['message' => 'That account is on a family (or is not a temporary parent at this agency), so it was not removed.'], 422);
        }

        return response()->json(['removed' => true]);
    }

    public function settings(Request $request): JsonResponse
    {
        $a = $this->agencyId($request, true);

        return response()->json(['settings' => TempGuardians::settings($a), 'waiting' => count(TempGuardians::list($a, null, 500))]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $a = $this->agencyId($request, true);
        $data = $request->validate(['remove_after_days' => ['required', 'integer', 'min:0', 'max:365']]);

        return response()->json(['settings' => TempGuardians::saveSettings($a, $data)]);
    }
}
