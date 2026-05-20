<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

/**
 * v22p51 — Public agency landing pages.
 * Each agency gets a SEO-friendly page at api.kiddietrac.com/agency/{slug}
 * (or /book/{slug}) that shows their hero, centres, and an embedded
 * tour-booking form. Anonymous; no auth required.
 */
final class LandingController extends Controller
{
    public function landing(Request $request, string $slug)
    {
        $agency = DB::table('agencies')->where('slug', $slug)->whereNull('deleted_at')->first();
        if (!$agency) {
            return Response::make('<!doctype html><html><body><h1>Agency not found</h1></body></html>', 404);
        }
        $centres = DB::table('centres')->where('agency_id', $agency->id)->whereNull('deleted_at')
            ->select('id', 'name', 'address', 'city', 'phone')
            ->orderBy('name')
            ->get();
        $html = view('landing.agency', [
            'agency' => $agency,
            'centres' => $centres,
        ])->render();
        return Response::make($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
