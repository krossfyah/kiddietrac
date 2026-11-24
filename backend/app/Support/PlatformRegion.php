<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHICH PLATFORM AN AGENCY IS ON (2026-10-25): "Canadian platform · Ontario", "U.S. platform · Ohio".
 * From the agency's own address; else its centres'; else estimated from its time zone (and said so).
 * Only facts the account holds go with it: tax, time zone, currency and the region's childcare programme.
 * It never claims where data is hosted.
 */
final class PlatformRegion
{
    public const CA = ['AB' => 'Alberta', 'BC' => 'British Columbia', 'MB' => 'Manitoba', 'NB' => 'New Brunswick', 'NL' => 'Newfoundland and Labrador',
        'NS' => 'Nova Scotia', 'NT' => 'Northwest Territories', 'NU' => 'Nunavut', 'ON' => 'Ontario', 'PE' => 'Prince Edward Island', 'QC' => 'Quebec',
        'SK' => 'Saskatchewan', 'YT' => 'Yukon'];
    public const US = ['AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut',
        'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana',
        'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan',
        'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey',
        'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania',
        'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia',
        'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming'];
    // Time zone -> [country, region] when nothing else is known (an estimate, labelled as one).
    private const TZ = ['America/Toronto' => ['CA', 'ON'], 'America/Vancouver' => ['CA', 'BC'], 'America/Edmonton' => ['CA', 'AB'], 'America/Winnipeg' => ['CA', 'MB'],
        'America/Regina' => ['CA', 'SK'], 'America/Halifax' => ['CA', 'NS'], 'America/St_Johns' => ['CA', 'NL'], 'America/Moncton' => ['CA', 'NB'],
        'America/New_York' => ['US', null], 'America/Chicago' => ['US', null], 'America/Denver' => ['US', null], 'America/Phoenix' => ['US', 'AZ'], 'America/Los_Angeles' => ['US', null]];

    public static function country(?string $c): ?string
    {
        $c = strtoupper(trim((string) $c));
        if ($c === '') return null;
        if (in_array($c, ['CA', 'CAN', 'CANADA'], true)) return 'CA';
        if (in_array($c, ['US', 'USA', 'U.S.', 'U.S.A.', 'UNITED STATES', 'UNITED STATES OF AMERICA', 'AMERICA'], true)) return 'US';
        return strlen($c) === 2 ? $c : null;
    }

    public static function region(?string $country, ?string $r): ?string
    {
        $r = trim((string) $r);
        if ($r === '' || ! $country) return null;
        $map = $country === 'CA' ? self::CA : ($country === 'US' ? self::US : []);
        $u = strtoupper($r);
        if (isset($map[$u])) return $u;
        foreach ($map as $code => $name) if (strcasecmp($name, $r) === 0) return $code;
        return null;
    }

    public static function forAgency(int $agencyId): array
    {
        $a = DB::table('agencies')->where('id', $agencyId)->first();
        $country = self::country($a->country ?? null);
        $code = self::region($country, $a->province ?? null);
        $source = $country ? 'agency' : null;
        if (! $country || ! $code) {
            $cols = Schema::getColumnListing('centres');
            if (in_array('province', $cols, true)) {
                foreach (DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')->orderBy('id')->get(array_values(array_intersect(['province', 'country'], $cols))) as $c) {
                    $cc = $country ?: (self::country($c->country ?? null) ?: ($c->province && isset(self::CA[strtoupper($c->province)]) ? 'CA' : null));
                    $rc = self::region($cc, $c->province ?? null);
                    if ($cc && $rc) { $country = $cc; $code = $rc; $source = $source ?: 'centre'; break; }
                }
            }
        }
        $estimated = false;
        if (! $country && ! empty($a->timezone) && isset(self::TZ[$a->timezone])) {
            [$country, $code] = self::TZ[$a->timezone];
            $source = 'timezone'; $estimated = true;
        }
        $name = $country === 'CA' ? 'Canada' : ($country === 'US' ? 'United States' : ($country ?: null));
        $adj = $country === 'CA' ? 'Canadian' : ($country === 'US' ? 'U.S.' : ($name ?: ''));
        $regionName = $code ? (($country === 'CA' ? self::CA : self::US)[$code] ?? null) : null;
        $flag = $country && strlen($country) === 2 ? mb_chr(0x1F1E6 + ord($country[0]) - 65) . mb_chr(0x1F1E6 + ord($country[1]) - 65) : '';
        $bps = (int) ($a->tax_rate_bps ?? 0);
        return [
            'country_code' => $country, 'country' => $name, 'region_code' => $code, 'region' => $regionName,
            'label' => $country ? trim($adj . ' platform' . ($regionName ? ' · ' . $regionName : '')) : null,
            'flag' => $flag, 'source' => $source, 'estimated' => $estimated,
            'facts' => array_values(array_filter([
                $bps > 0 ? ['k' => 'Tax', 'v' => trim(($a->tax_label ?: 'Tax') . ' ' . rtrim(rtrim(number_format($bps / 100, 2), '0'), '.') . '%')] : null,
                ! empty($a->timezone) ? ['k' => 'Time zone', 'v' => str_replace('_', ' ', (string) $a->timezone)] : null,
                ['k' => 'Currency', 'v' => strtoupper((string) ($a->plan_currency ?: ($country === 'US' ? 'USD' : 'CAD')))],
                $country === 'CA' ? ['k' => 'Childcare programme', 'v' => 'CWELCC reporting'] : ($country === 'US' ? ['k' => 'Childcare programme', 'v' => 'CACFP reporting'] : null),
            ])),
        ];
    }

    /**
     * What every licence includes besides the feature flags: support and maintenance, the Message Centre and
     * One-Tap check-in (the QR check-in, by its product name). Each follows the flag that switches it.
     */
    public static function included(array $flags): array
    {
        return [
            ['key' => 'support', 'label' => 'Support & maintenance', 'detail' => 'Help desk, updates and security patches, included with every plan', 'on' => true],
            ['key' => 'message_centre', 'label' => 'Message Centre', 'detail' => 'Messages, announcements and alerts to families and staff in one place', 'on' => (bool) ($flags['chat'] ?? true)],
            ['key' => 'one_tap', 'label' => 'One-Tap check-in', 'detail' => 'Families check in and out with one tap on the centre QR code', 'on' => (bool) ($flags['attendance'] ?? true)],
        ];
    }
}
