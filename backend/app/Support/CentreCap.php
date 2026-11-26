<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * HOW MANY CENTRES AN AGENCY'S LICENCE ALLOWS (2026-11-26).
 *
 * Anthony: "Reseller bundle includes 5 centres, CONFIGURABLE ... per product, and optionally a per-agency override;
 * default 5. Each 'Reseller extra centre' line adds one." and "don't block existing centres; only block creating NEW
 * ones beyond the cap" and "closed centres don't count".
 *
 *   cap  = included + extras, for an agency whose live licence holds a product with sales_products.included_centres
 *          (the Reseller bundle: 5). The per-agency override (agencies.settings.centre_cap_included, set on the
 *          platform Licences screen) replaces the product's included number; extra-centre lines still add.
 *          No such product on the licence = no cap (null): nothing changes for anyone else.
 *   used = the agency's open centres (App\Support\Live::centres - closed, archived, inactive, deleted don't count).
 *
 * Every path that makes a centre count again - creating one (admin screen, setup wizard, sign-up, platform,
 * integrations/imports, console) and reopening a closed one - asks refusal() first; kt:addon-coverage fails when a
 * file inserts into `centres` without asking. An agency already over its cap keeps every centre it has.
 */
final class CentreCap
{
    public const EXTRA_PRODUCT = 'Reseller extra centre';
    public const SETTING = 'centre_cap_included';

    /** null = no cap. */
    public static function capFor(int $agencyId): ?int
    {
        if ($agencyId <= 0 || ! \Illuminate\Support\Facades\Schema::hasColumn('sales_products', 'included_centres')) {
            return null;
        }
        $included = DB::table('agency_licences as l')->join('sales_products as p', 'p.id', '=', 'l.product_id')
            ->where('l.agency_id', $agencyId)->whereIn('l.status', Licences::LIVE)->whereNotNull('p.included_centres')
            ->max('p.included_centres');
        if ($included === null) {
            return null;
        }
        $override = self::override($agencyId);

        return ($override ?? (int) $included) + self::extras($agencyId);
    }

    public static function override(int $agencyId): ?int
    {
        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];
        $v = $s[self::SETTING] ?? null;

        return is_numeric($v) && (int) $v >= 0 ? (int) $v : null;
    }

    public static function setOverride(int $agencyId, ?int $included): void
    {
        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];
        if ($included === null) {
            unset($s[self::SETTING]);
        } else {
            $s[self::SETTING] = max(0, $included);
        }
        DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
    }

    /** Extra centres bought: the qty on the agency's live "Reseller extra centre" lines. */
    public static function extras(int $agencyId): int
    {
        $pid = DB::table('sales_products')->where('name', self::EXTRA_PRODUCT)->where('kind', 'addon')->value('id');
        if (! $pid) {
            return 0;
        }

        return (int) floor((float) DB::table('agency_licences')->where('agency_id', $agencyId)->where('product_id', $pid)
            ->whereIn('status', Licences::LIVE)->sum('qty'));
    }

    public static function used(int $agencyId): int
    {
        return Live::centres(DB::table('centres')->where('agency_id', $agencyId), 'centres')->count();
    }

    /** {cap, used, left, over, override} - cap null = uncapped. */
    public static function status(int $agencyId): array
    {
        $cap = self::capFor($agencyId);
        $used = self::used($agencyId);

        return ['cap' => $cap, 'used' => $used, 'left' => $cap === null ? null : max(0, $cap - $used),
            'over' => $cap !== null && $used > $cap, 'override' => self::override($agencyId)];
    }

    /** May this agency open $adding more centres? */
    public static function allows(int $agencyId, int $adding = 1): bool
    {
        $cap = self::capFor($agencyId);

        return $cap === null || self::used($agencyId) + $adding <= $cap;
    }

    /**
     * The refusal to return instead of opening a centre, or null when it may. 403 with the same "upsell" payload
     * the Upgrade popup reads (api-helpers-v10.js raises kt:upsell, app-v2-shell opens the popup).
     */
    public static function refusal(int $agencyId, int $adding = 1): ?\Illuminate\Http\JsonResponse
    {
        if (self::allows($agencyId, $adding)) {
            return null;
        }
        $st = self::status($agencyId);
        $msg = 'Your licence includes ' . $st['cap'] . ' centre' . ($st['cap'] === 1 ? '' : 's') . ' and ' . $st['used']
            . ' are open. Add an extra centre to your licence to open another.';

        return response()->json([
            'message' => $msg,
            'code' => 'centre_cap',
            'centre_cap' => $st,
            'upsell' => [
                'feature' => 'centre_cap',
                'label' => 'Another centre',
                'blurb' => $msg,
                'via' => 'the ' . self::EXTRA_PRODUCT . ' add-on (one more centre each)',
                'note' => 'Your open centres stay open; only opening another one needs the add-on.',
            ],
        ], 403);
    }
}
