<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * ADDRESS AUTOCOMPLETE (2026-10-13, Anthony: "auto complete address when entering new family").
 *
 * GET /address/suggest?q=120 Broadway Orangeville&country=CA
 *   → { suggestions: [{ label, address_line1, city, province, postal_code, country }] }
 *
 * Backed by Photon (photon.komoot.io — OpenStreetMap data, no key). Canada and the US only,
 * the chosen country first. Cached for a week per query: the same street is typed again and
 * again, and the service is a shared free one, so we ask it as little as possible. The
 * browser debounces, and this route is rate-limited.
 */
class AddressController extends Controller
{
    private const CA = ['alberta' => 'AB', 'british columbia' => 'BC', 'manitoba' => 'MB', 'new brunswick' => 'NB',
        'newfoundland and labrador' => 'NL', 'nova scotia' => 'NS', 'northwest territories' => 'NT', 'nunavut' => 'NU',
        'ontario' => 'ON', 'prince edward island' => 'PE', 'quebec' => 'QC', 'québec' => 'QC', 'saskatchewan' => 'SK', 'yukon' => 'YT'];
    private const US = ['alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR', 'california' => 'CA', 'colorado' => 'CO',
        'connecticut' => 'CT', 'delaware' => 'DE', 'district of columbia' => 'DC', 'florida' => 'FL', 'georgia' => 'GA', 'hawaii' => 'HI',
        'idaho' => 'ID', 'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS', 'kentucky' => 'KY', 'louisiana' => 'LA',
        'maine' => 'ME', 'maryland' => 'MD', 'massachusetts' => 'MA', 'michigan' => 'MI', 'minnesota' => 'MN', 'mississippi' => 'MS',
        'missouri' => 'MO', 'montana' => 'MT', 'nebraska' => 'NE', 'nevada' => 'NV', 'new hampshire' => 'NH', 'new jersey' => 'NJ',
        'new mexico' => 'NM', 'new york' => 'NY', 'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH', 'oklahoma' => 'OK',
        'oregon' => 'OR', 'pennsylvania' => 'PA', 'rhode island' => 'RI', 'south carolina' => 'SC', 'south dakota' => 'SD',
        'tennessee' => 'TN', 'texas' => 'TX', 'utah' => 'UT', 'vermont' => 'VT', 'virginia' => 'VA', 'washington' => 'WA',
        'west virginia' => 'WV', 'wisconsin' => 'WI', 'wyoming' => 'WY'];

    public function suggest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:4', 'max:160'],
            'country' => ['nullable', 'in:CA,US'],
        ]);
        $q = trim(preg_replace('/\s+/', ' ', $data['q']));
        $want = $data['country'] ?? 'CA';
        $key = 'addr:' . md5(mb_strtolower($q));

        $raw = Cache::remember($key, now()->addDays(7), function () use ($q) {
            try {
                $r = Http::timeout(6)->acceptJson()->get('https://photon.komoot.io/api/', [
                    'q' => $q, 'limit' => 10, 'lang' => 'en',
                ]);
                return $r->ok() ? ($r->json('features') ?? []) : [];
            } catch (\Throwable $e) {
                return null;   // not cached as "no results" — see below
            }
        });
        if ($raw === null) { Cache::forget($key); $raw = []; }

        $out = [];
        foreach ($raw as $f) {
            $p = $f['properties'] ?? [];
            $cc = strtoupper((string) ($p['countrycode'] ?? ''));
            if (! in_array($cc, ['CA', 'US'], true)) continue;
            $street = trim(((string) ($p['housenumber'] ?? '')) . ' ' . ((string) ($p['street'] ?? ($p['type'] === 'street' ? ($p['name'] ?? '') : ''))));
            if ($street === '') continue;   // a city or a region on its own is not an address
            $state = mb_strtolower((string) ($p['state'] ?? ''));
            $prov = $cc === 'CA' ? (self::CA[$state] ?? '') : (self::US[$state] ?? '');
            $city = (string) ($p['city'] ?? ($p['town'] ?? ($p['village'] ?? ($p['locality'] ?? ($p['district'] ?? '')))));
            $postal = strtoupper(trim((string) ($p['postcode'] ?? '')));
            if ($cc === 'CA' && preg_match('/^([A-Z]\d[A-Z])\s*(\d[A-Z]\d)$/', $postal, $m)) $postal = $m[1] . ' ' . $m[2];
            $label = $street . ($city !== '' ? ', ' . $city : '') . ($prov !== '' ? ', ' . $prov : '') . ($postal !== '' ? ' ' . $postal : '') . ($cc !== $want ? ' · ' . ($cc === 'US' ? 'USA' : 'Canada') : '');
            $row = ['label' => $label, 'address_line1' => $street, 'city' => $city, 'province' => $prov, 'postal_code' => $postal, 'country' => $cc];
            if (! in_array($row, $out, true)) $out[] = $row;
        }
        // The chosen country first, then the other.
        usort($out, fn ($a, $b) => ($a['country'] === $want ? 0 : 1) <=> ($b['country'] === $want ? 0 : 1));

        return response()->json(['suggestions' => array_slice($out, 0, 6)]);
    }
}
