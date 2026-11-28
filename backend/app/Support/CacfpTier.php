<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CACFP meal-claim tier of a child on a date (2026-11-28).
 *
 * The tier lives on the FAMILY (families.cacfp_tier / cacfp_eligibility_date / cacfp_evidence_note) and a CHILD may
 * override it (children.cacfp_tier ...), for a child whose eligibility differs from the household's (a foster child,
 * a Tier I child in a Tier II home). Tiers:
 *   tier1, tier2         family child care homes (Tier I / Tier II)
 *   free, reduced, paid  centres (Free / Reduced / Paid)
 * An income-based tier (Tier I, Free, Reduced) counts from its eligibility date for 12 months, the CACFP rule; after
 * that, or before it, the child has no tier on file and the claim counts those meals apart ("No tier") so they can be
 * renewed. Tier II and Paid need no eligibility and do not expire.
 *
 * Each served meal keeps the tier it was claimed under (cacfp_meals.eligibility_tier): written when the meal is
 * ticked and re-stamped (restamp) for meals on or after a changed eligibility date, so the claim for a month is the
 * same whether it is printed today or next year.
 */
final class CacfpTier
{
    public const TIERS = ['tier1' => 'Tier I', 'tier2' => 'Tier II', 'free' => 'Free', 'reduced' => 'Reduced', 'paid' => 'Paid'];
    public const INCOME_BASED = ['tier1', 'free', 'reduced'];
    public const MEALS = ['breakfast' => 'Breakfast', 'morning_snack' => 'AM snack', 'lunch' => 'Lunch', 'afternoon_snack' => 'PM snack', 'dinner' => 'Supper'];

    /** Rows for these children: [child_id => {child_tier, child_date, child_note, family_id, family_tier, family_date, family_note}]. */
    public static function settingsFor(array $childIds): array
    {
        if (! $childIds) return [];
        return DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
            ->whereIn('ch.id', $childIds)
            ->get(['ch.id', 'ch.family_id', 'ch.cacfp_tier as child_tier', 'ch.cacfp_eligibility_date as child_date', 'ch.cacfp_evidence_note as child_note',
                'f.cacfp_tier as family_tier', 'f.cacfp_eligibility_date as family_date', 'f.cacfp_evidence_note as family_note'])
            ->keyBy('id')->all();
    }

    /**
     * The tier that applies on $date, with where it came from.
     * @return array{tier: ?string, source: ?string, set: ?string, eligible_from: ?string, expires: ?string, expired: bool, note: ?string}
     */
    public static function describe(?object $s, string $date): array
    {
        $out = ['tier' => null, 'source' => null, 'set' => null, 'eligible_from' => null, 'expires' => null, 'expired' => false, 'note' => null];
        if (! $s) return $out;
        [$tier, $from, $note, $src] = ! empty($s->child_tier)
            ? [$s->child_tier, $s->child_date ?: $s->family_date, $s->child_note, 'child']
            : [$s->family_tier ?? null, $s->family_date ?? null, $s->family_note ?? null, 'family'];
        if (! $tier || ! isset(self::TIERS[$tier])) return $out;
        $out['set'] = $tier;
        $out['source'] = $src;
        $out['note'] = $note;
        $out['eligible_from'] = $from ? substr((string) $from, 0, 10) : null;
        if (in_array($tier, self::INCOME_BASED, true)) {
            if (! $out['eligible_from']) { $out['expired'] = true; return $out; }
            $out['expires'] = Carbon::parse($out['eligible_from'])->addYear()->subDay()->toDateString();
            if ($date < $out['eligible_from'] || $date > $out['expires']) {
                $out['expired'] = $date > $out['expires'];
                return $out;
            }
        }
        $out['tier'] = $tier;
        return $out;
    }

    public static function on(?object $s, string $date): ?string
    {
        return self::describe($s, $date)['tier'];
    }

    /** Re-stamp the tier on these children's served meals from $from on (a changed tier or eligibility date). */
    public static function restamp(array $childIds, ?string $from = null): int
    {
        $settings = self::settingsFor($childIds);
        $n = 0;
        DB::table('cacfp_meals')->whereIn('child_id', $childIds ?: [0])
            ->when($from, fn ($q) => $q->where('meal_date', '>=', $from))
            ->select('id', 'child_id', 'meal_date', 'eligibility_tier')->orderBy('id')
            ->chunk(500, function ($rows) use ($settings, &$n) {
                foreach ($rows as $m) {
                    $t = self::on($settings[$m->child_id] ?? null, substr((string) $m->meal_date, 0, 10));
                    if ($t !== $m->eligibility_tier) {
                        DB::table('cacfp_meals')->where('id', $m->id)->update(['eligibility_tier' => $t]);
                        $n++;
                    }
                }
            });
        return $n;
    }
}
