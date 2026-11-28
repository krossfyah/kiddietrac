<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PrivateFiles;
use App\Support\WalkMap;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Walk Watch route map drawn from 2026-11-28 on (App\Support\WalkMap), from the private disk.
 *
 * No bearer token: the link goes in an email <img src>. The token carries its own HMAC and expiry (30 days for emails,
 * 12 hours for the portal) and names the walk and its agency; the walk must still belong to that agency, and the file
 * is read only from that agency's folder. Anything else is a 404, never a hint.
 */
class WalkMapFileController extends Controller
{
    public function show(string $token): Response
    {
        $v = WalkMap::verify($token);
        abort_if($v === null, 404);
        [$tripId, $agencyId] = $v;
        $trip = DB::table('field_trips')->where('id', $tripId)->first(['agency_id', 'map_token']);
        abort_if(! $trip || (int) $trip->agency_id !== $agencyId || ! $trip->map_token, 404);
        $path = WalkMap::privatePath($agencyId, (string) $trip->map_token);
        abort_unless(PrivateFiles::disk()->exists($path), 404);

        $resp = PrivateFiles::stream($path, 'walk-map.png');
        // Mail proxies (Gmail) fetch once and keep it; the link itself is the expiry.
        $resp->headers->set('Cache-Control', 'private, max-age=86400');

        return $resp;
    }
}
