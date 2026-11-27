<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\CentreScope;
use App\Support\HrClock;
use App\Support\HrLeave;
use App\Support\Live;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * KiddieTrac HR Phase 3, item 11: clock-in rules (App\Support\HrClock). Directors (own centres) and admins read the
 * watch list; only admins change the agency rule and the centre fences.
 */
class HrClockController extends Controller
{
    use HrAccess;

    private function centreIds(Request $request, int $aid): array
    {
        $open = Live::centreIds($aid);
        if ($this->hrIsAdmin($request)) return $open;

        return array_values(array_intersect($open, CentreScope::ids($request, $aid) ?? []));
    }

    /** GET /hr/clock/settings */
    public function show(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $centres = DB::table('centres')->whereIn('id', $this->centreIds($request, $aid) ?: [0])->orderBy('name')
            ->get(['id', 'name', 'address_line1', 'city', 'settings', 'latitude', 'longitude']);

        return response()->json(['settings' => HrClock::settings($aid), 'is_admin' => $this->hrIsAdmin($request), 'consent' => HrClock::CONSENT,
            'centres' => $centres->map(function ($c) {
                $s = json_decode((string) ($c->settings ?? ''), true);
                $g = is_array($s['geofence'] ?? null) ? $s['geofence'] : null;

                return ['id' => (int) $c->id, 'name' => $c->name, 'address' => trim(implode(', ', array_filter([$c->address_line1 ?? null, $c->city ?? null]))),
                    'latitude' => $c->latitude !== null ? (float) $c->latitude : null, 'longitude' => $c->longitude !== null ? (float) $c->longitude : null,
                    'radius_m' => $g['radius_m'] ?? null, 'lat' => $g['lat'] ?? null, 'lng' => $g['lng'] ?? null, 'fence' => HrClock::fence($c)];
            })->all()]);
    }

    /** PUT /hr/clock/settings (admin) */
    public function save(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        $d = $request->validate(['geofence' => 'required|in:off,flag,block', 'overtime_on' => 'boolean', 'overtime_hours' => 'required|numeric|min:20|max:80',
            'warn_at_hours' => 'required|numeric|min:1|max:80', 'skip_contractors' => 'boolean']);
        abort_if((float) $d['warn_at_hours'] > (float) $d['overtime_hours'], 422, 'The warning comes at or before the overtime threshold.');
        HrClock::saveSettings($aid, $d);
        $this->hrNote($aid, 'hr.clock_settings_saved', 'agency', $aid, $d);

        return response()->json(['settings' => HrClock::settings($aid)]);
    }

    /** PUT /hr/clock/centres/{id}/fence (admin) {radius_m: null = no fence, lat, lng: null = from the address} */
    public function saveFence(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        abort_unless(in_array($id, Live::centreIds($aid), true), 404, 'That centre was not found.');
        $d = $request->validate(['radius_m' => 'nullable|integer|min:25|max:5000', 'lat' => 'nullable|numeric|between:-90,90|required_with:lng',
            'lng' => 'nullable|numeric|between:-180,180|required_with:lat']);
        $fence = empty($d['radius_m']) ? null : ['radius_m' => (int) $d['radius_m'], 'lat' => isset($d['lat']) ? round((float) $d['lat'], 7) : null, 'lng' => isset($d['lng']) ? round((float) $d['lng'], 7) : null];
        if ($fence && $fence['lat'] === null) {
            $c = DB::table('centres')->where('id', $id)->first(['latitude', 'longitude']);
            abort_if(! $c || $c->latitude === null, 422, 'This centre has no map location from its address. Pick the spot on the map.');
        }
        HrClock::saveFence($id, $fence);
        $this->hrNote($aid, 'hr.geofence_saved', 'centre', $id, $fence ? ['radius_m' => $fence['radius_m'], 'picked_on_map' => $fence['lat'] !== null] : ['removed' => true]);

        return response()->json(['ok' => true]);
    }

    /** GET /hr/clock/watch — this week's clock-ins away from the centre and people near or into overtime. */
    public function watch(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $cids = $this->centreIds($request, $aid);
        $people = HrLeave::staffIds($aid, $this->hrIsAdmin($request) ? null : $cids);
        $s = HrClock::settings($aid);
        $from = AgencyTime::boundaryUtc($aid, 'week')->format('Y-m-d H:i:s');
        $flag = DB::table('time_punches')->whereIn('centre_id', $cids ?: [0])->whereIn('user_id', $people ?: [0])->where('punched_in_at', '>=', $from)
            ->whereIn('geo_status', ['outside', 'no_location'])->orderByDesc('punched_in_at')->limit(100)->get(['id', 'user_id', 'centre_id', 'punched_in_at', 'geo_status', 'geo_distance_m']);
        $hours = HrClock::weekHours($aid, $people);
        $contract = $s['skip_contractors'] ? HrClock::contractorIds($aid, array_keys($hours)) : [];
        $ot = [];
        foreach ($hours as $uid => $h) {
            if ($h >= $s['warn_at_hours'] && ! in_array($uid, $contract, true)) $ot[$uid] = $h;
        }
        arsort($ot);
        $names = $this->hrNames(array_merge($flag->pluck('user_id')->all(), array_keys($ot)));
        $cn = DB::table('centres')->whereIn('id', $cids ?: [0])->pluck('name', 'id')->all();
        $tz = AgencyTime::tz($aid);

        return response()->json(['settings' => $s, 'is_admin' => $this->hrIsAdmin($request), 'week' => Carbon::now($tz)->startOfWeek()->toDateString(),
            'away' => $flag->map(fn ($p) => ['id' => (int) $p->id, 'person' => $names[(int) $p->user_id] ?? '', 'centre' => $cn[(int) $p->centre_id] ?? '',
                'at' => Carbon::parse($p->punched_in_at, 'UTC')->setTimezone($tz)->format('Y-m-d H:i'), 'status' => $p->geo_status,
                'distance' => $p->geo_distance_m !== null ? HrClock::far((int) $p->geo_distance_m) : null])->all(),
            'overtime' => collect($ot)->map(fn ($h, $uid) => ['user_id' => $uid, 'person' => $names[$uid] ?? '', 'hours' => $h, 'over' => $h >= $s['overtime_hours']])->values()->all()]);
    }
}
