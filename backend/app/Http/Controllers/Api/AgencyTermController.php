<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Per-agency facility terminology: whether the agency calls its facilities
 * "Centres" or "Rooms". Stored in agencies.settings->centre_term. The GET is
 * readable by ANY authenticated user (so the frontend label-swapper works for
 * every role); the setter is agency-admin only. Default 'centre'.
 */
final class AgencyTermController extends Controller
{
    private function resolveAgencyId(Request $request): ?int
    {
        $h = $request->header('X-Active-Agency-Id');
        $hid = (int) $h;
        if ($hid && strtolower(trim((string) $h)) !== 'all' && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
                ->where(function ($q) use ($hid) { $q->where('role', 'platform_admin')->orWhere('agency_id', $hid); })->exists()) {
            return $hid;
        }
        $u = $request->user();
        $aid = $u ? DB::table('role_assignments')->where('user_id', $u->id)->where('active', 1)->value('agency_id') : null;
        return $aid ? (int) $aid : null;
    }

    private function readTerm(?int $agencyId): string
    {
        if (! $agencyId) {
            return 'centre';
        }
        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        $s = ($row && $row->settings) ? (json_decode($row->settings, true) ?: []) : [];
        $t = $s['centre_term'] ?? 'centre';
        return in_array($t, ['centre', 'room', 'provider'], true) ? $t : 'centre';
    }

    private const COUNTRY_LABELS = [
        'CA' => ['Province', 'Postal code'],
        'US' => ['State', 'ZIP code'],
        'GB' => ['County', 'Postcode'],
        'AU' => ['State', 'Postcode'],
        'NZ' => ['Region', 'Postcode'],
        'IE' => ['County', 'Eircode'],
    ];

    private function readCountry(?int $agencyId): string
    {
        if (! $agencyId) {
            return 'CA';
        }
        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        $s = ($row && $row->settings) ? (json_decode($row->settings, true) ?: []) : [];
        $c = $s['country'] ?? 'CA';
        return isset(self::COUNTRY_LABELS[$c]) ? $c : 'CA';
    }

    /** GET /agency/centre-term — facility term + country-specific address labels. */
    public function show(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $t = $this->readTerm($agencyId);
        $country = $this->readCountry($agencyId);
        $lbl = self::COUNTRY_LABELS[$country];
        $labels = ['centre' => ['Centre', 'Centres'], 'room' => ['Room', 'Rooms'], 'provider' => ['Provider', 'Providers']];
        $sl = $labels[$t] ?? $labels['centre'];
        return response()->json([
            'term'        => $t,
            'singular'    => $sl[0],
            'plural'      => $sl[1],
            'country'     => $country,
            'state_label' => $lbl[0],
            'zip_label'   => $lbl[1],
        ]);
    }

    /** POST /admin/centre-term */
    public function set(Request $request): JsonResponse
    {
        $u = $request->user();
        $ok = DB::table('role_assignments')->where('user_id', $u->id)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin'])->exists();
        abort_unless($ok, 403, 'Admin only');

        $data = $request->validate(['term' => ['required', 'in:centre,room,provider']]);
        $agencyId = $this->resolveAgencyId($request);
        abort_unless($agencyId, 404, 'No active agency');

        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        $s = ($row && $row->settings) ? (json_decode($row->settings, true) ?: []) : [];
        $s['centre_term'] = $data['term'];
        DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);

        return response()->json(['status' => 'saved', 'term' => $data['term']]);
    }
}
