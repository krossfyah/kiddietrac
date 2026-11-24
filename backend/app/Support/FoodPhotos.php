<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FOOD PHOTOS ON THE WEEKLY MENU (2026-10-31, Anthony: "with the meal plan can the educators choose pictures for common
 * foods and show this like how you have this in the emulator ... or have AI do this on their behalf").
 *
 * - The LIBRARY (food_images): ~160 common childcare foods and drinks, every photo CC0 / public domain from Wikimedia
 *   Commons, credited row by row, served from our own storage (/storage/food-library/, public on purpose: stock food,
 *   no people). Names carry English, French and Spanish synonyms and an allergen HINT.
 * - MATCHING is local first: a dish name is compared with every food's name and synonyms (accents, plurals, "with/and"
 *   ignored). A confident match (score >= AUTO) is applied automatically when the agency's "Add food photos
 *   automatically" is on (default); weaker ones are only offered. The AI Bundle helper (AiAssistController::foodPhoto)
 *   handles the dishes this cannot place, choosing only from the library's own slugs.
 * - An agency can also upload its own photo: /storage/photos/menu/<agency>/… (the protected, signed photos folder).
 * - menu_items.image_source remembers library | upload | auto | none; "none" (removed on purpose) is never refilled.
 */
final class FoodPhotos
{
    public const AUTO = 60;          // apply automatically at or above this score
    public const SUGGEST = 30;       // offer at or above this
    public const LIB_PREFIX = '/storage/food-library/';
    private const STOP = ['with', 'and', 'on', 'of', 'the', 'a', 'an', 'au', 'aux', 'de', 'du', 'des', 'la', 'le', 'les', 'et',
        'con', 'y', 'el', 'del', 'en', 'w', 'n', 'served', 'side', 'fresh', 'homemade', 'plus', 'or', 'ou', 'o', 'some', 'small',
        'warm', 'cold', 'hot', 'kids', 'kid'];

    /** The active library, cached for an hour (cleared by the import command). */
    public static function library(): array
    {
        return Cache::remember('food-library:v1', 3600, function () {
            return DB::table('food_images')->where('active', true)->orderBy('name')
                ->get(['id', 'slug', 'name', 'synonyms', 'allergens', 'category', 'url', 'thumb_url', 'author', 'licence', 'source_url'])
                ->map(fn ($r) => (array) $r)->all();
        });
    }

    public static function forget(): void { Cache::forget('food-library:v1'); }

    /** "Add food photos automatically" - on unless the agency switched it off. */
    public static function autoOn(?int $agencyId): bool
    {
        if (! $agencyId) return false;
        $s = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $a = is_string($s) ? (json_decode($s, true) ?: []) : (array) $s;
        return ($a['menu_auto_photos'] ?? true) !== false;
    }

    public static function setAuto(int $agencyId, bool $on): void
    {
        $s = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $a = is_string($s) ? (json_decode($s, true) ?: []) : (array) $s;
        $a['menu_auto_photos'] = $on;
        DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($a)]);
    }

    // ── matching ──────────────────────────────────────────────────────────────────────────────

    public static function norm(?string $s): string
    {
        $s = Str::ascii(mb_strtolower(trim((string) $s)));
        $s = preg_replace("/'s\b/", 's', $s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        $out = [];
        foreach (preg_split('/\s+/', trim($s)) as $w) {
            if ($w === '' || in_array($w, self::STOP, true)) continue;
            $out[] = self::singular($w);
        }
        return implode(' ', $out);
    }

    private static function singular(string $w): string
    {
        if (strlen($w) <= 3) return $w;
        if (str_ends_with($w, 'ies')) return substr($w, 0, -3) . 'y';
        if (str_ends_with($w, 'oes')) return substr($w, 0, -2);
        if (str_ends_with($w, 'ches') || str_ends_with($w, 'shes') || str_ends_with($w, 'xes')) return substr($w, 0, -2);
        if (str_ends_with($w, 's') && ! str_ends_with($w, 'ss') && ! str_ends_with($w, 'us')) return substr($w, 0, -1);
        return $w;
    }

    /** Normalised phrases for one library food: its name and every synonym. */
    private static function phrases(array $f): array
    {
        $p = [self::norm($f['name'])];
        foreach (explode(',', (string) ($f['synonyms'] ?? '')) as $syn) {
            $n = self::norm($syn);
            if ($n !== '') $p[] = $n;
        }
        return array_values(array_unique(array_filter($p)));
    }

    /** 0..~105: how well one food fits a dish name. */
    public static function score(string $dishNorm, array $f): float
    {
        if ($dishNorm === '') return 0;
        $td = array_values(array_unique(explode(' ', $dishNorm)));
        $best = 0.0; $covered = [];
        foreach (self::phrases($f) as $p) {
            $tp = array_values(array_unique(explode(' ', $p)));
            if ($p === $dishNorm) return 100.0;
            $in = array_intersect($tp, $td);
            foreach ($in as $w) $covered[$w] = true;
            if (count($in) === count($tp)) {
                // The dish's main food usually comes first ("Cheerios with milk", "Oatmeal with blueberries"): a phrase
                // matching earlier in the name wins a tie.
                $first = min(array_map(fn ($w) => array_search($w, $td, true), $tp));
                $s = 60 + 30 * count($tp) / max(1, count($td)) + 2 * count($tp) + 3 * (1 - $first / max(1, count($td)));
            } elseif ($in) {
                $s = 30 * count($in) / max(count($tp), count($td));
            } else {
                continue;
            }
            $best = max($best, $s);
        }
        return $best ? $best + 5 * count($covered) / max(1, count($td)) : 0.0;
    }

    private static ?array $vocab = null;

    /**
     * Menus are typed fast ("Yugert fruit", "Cheeroes", "Veges and dip" - iLearn's real weeks). A word the library does
     * not know is read as the library word it is one or two letters away from (same first letter; words of 5+ letters:
     * 1 edit at 5, 2 from 6), so a typo still finds its photo.
     */
    public static function spell(string $norm): string
    {
        if (self::$vocab === null) {
            self::$vocab = [];
            foreach (self::library() as $f) foreach (self::phrases($f) as $p) foreach (explode(' ', $p) as $w) self::$vocab[$w] = true;
        }
        $out = [];
        foreach (explode(' ', $norm) as $w) {
            if ($w === '' || isset(self::$vocab[$w]) || strlen($w) < 5) { $out[] = $w; continue; }
            $max = strlen($w) >= 6 ? 2 : 1; $best = null; $bd = 99;
            foreach (self::$vocab as $v => $_) {
                $v = (string) $v;   // numeric words ("2" from "2% milk") come back as int keys
                if ($v === '' || $v[0] !== $w[0] || abs(strlen($v) - strlen($w)) > $max) continue;
                $d = levenshtein($w, $v);
                if ($d < $bd) { $bd = $d; $best = $v; }
            }
            $out[] = ($best !== null && $bd <= $max) ? $best : $w;
        }
        return implode(' ', $out);
    }

    /** The best library foods for a dish, best first: [{id, slug, name, url, thumb_url, score}]. */
    public static function suggest(string $dish, int $limit = 5): array
    {
        $n = self::spell(self::norm($dish));
        if ($n === '') return [];
        $all = [];
        foreach (self::library() as $f) {
            $s = self::score($n, $f);
            // "Muffin milk", "Partha milk eggs": a drink is the side, not the dish, when the name says more.
            if ($s > 0 && $s < 100 && ($f['category'] ?? '') === 'drink' && substr_count($n, ' ') >= 1) $s -= 8;
            if ($s >= self::SUGGEST) $all[] = ['id' => (int) $f['id'], 'slug' => $f['slug'], 'name' => $f['name'],
                'url' => $f['url'], 'thumb_url' => $f['thumb_url'], 'allergens' => $f['allergens'], 'score' => round($s, 1)];
        }
        usort($all, fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($all, 0, $limit);
    }

    /**
     * Sure enough that AI would not do better: a strong score with a clear lead over the runner-up. Below this the
     * editor asks the AI Bundle helper (when the agency has it) and keeps the local match only if the AI has none.
     */
    public static function confident(array $suggestions): bool
    {
        if (! $suggestions) return false;
        $a = $suggestions[0]['score']; $b = $suggestions[1]['score'] ?? 0;
        return $a >= 88 && ($a - $b) >= 4;
    }

    /** The one food to apply automatically, or null when nothing is confident enough. */
    public static function best(string $dish): ?array
    {
        $s = self::suggest($dish, 1);
        return ($s && $s[0]['score'] >= self::AUTO) ? $s[0] : null;
    }

    /**
     * THE PHOTO TO SHOW for each menu item (2026-11-02, Anthony: "for meal plan i dont see the images showing up in
     * the daily overview for educators and also in the meal plan section").
     *
     * A photo used to be attached only when a week was SAVED after photos existed (saveMenuWeek -> menuPhoto). Every
     * week written before 2026-10-31 - every iLearn provider's menu but one - had image_url NULL and stayed that
     * way, so the screens had nothing to draw. The backfill (kt:menu-photos) was never applied to real agencies.
     * Readers now resolve the same confident match saveMenuWeek would have stored, without writing anything:
     *   - a stored photo (library, upload, auto) is shown as it is;
     *   - image_source 'none' (removed on purpose) stays empty;
     *   - otherwise, when the agency has "Add food photos automatically" on, the best confident library match,
     *     marked image_source 'auto' - so the editor shows "Picked for you" and the next Save stores it.
     * Rows are objects (DB rows) or arrays; they are changed in place and returned.
     */
    public static function forDisplay(iterable $items, ?int $agencyId): iterable
    {
        $auto = null; $memo = [];
        foreach ($items as $k => $it) {
            $isArr = is_array($it);
            $get = fn ($f) => $isArr ? ($it[$f] ?? null) : ($it->{$f} ?? null);
            if (trim((string) $get('image_url')) !== '' || $get('image_source') === 'none') continue;
            $auto ??= self::autoOn($agencyId);
            if (! $auto) break;
            $nm = (string) $get('name');
            if (! array_key_exists($nm, $memo)) $memo[$nm] = $nm === '' ? null : self::best($nm);
            $b = $memo[$nm];
            if (! $b) continue;
            if ($isArr) {
                $it['image_url'] = $b['thumb_url']; $it['image_source'] = 'auto'; $it['food_image_id'] = $b['id'];
                if (is_array($items)) $items[$k] = $it;
            } else {
                $it->image_url = $b['thumb_url']; $it->image_source = 'auto'; $it->food_image_id = $b['id'];
            }
        }
        return $items;
    }

    /** Library rows store absolute URLs (they must load in the app shells too); match them by path. */
    public static function libraryByPath(?string $pathOrUrl): ?array
    {
        $p = (string) parse_url((string) $pathOrUrl, PHP_URL_PATH);
        if ($p === '' || ! str_starts_with($p, self::LIB_PREFIX)) return null;
        foreach (self::library() as $f) {
            if (parse_url($f['url'], PHP_URL_PATH) === $p || parse_url($f['thumb_url'], PHP_URL_PATH) === $p) return $f;
        }
        return null;
    }

    /** Absolute base for library files: the API host serves /storage/food-library/ publicly. */
    public static function base(): string
    {
        return rtrim((string) (config('app.url') ?: 'https://api.kiddietrac.com'), '/');
    }

    public static function bySlug(string $slug): ?array
    {
        foreach (self::library() as $f) if ($f['slug'] === $slug) return $f;
        return null;
    }

    public static function byId(int $id): ?array
    {
        foreach (self::library() as $f) if ((int) $f['id'] === $id) return $f;
        return null;
    }

    // ── urls ──────────────────────────────────────────────────────────────────────────────────

    /**
     * What a client sends back is what it was handed: a SIGNED media URL for protected photos
     * (…/media/f?p=<base64url>&signature=…). Turn it back into the stored /storage/… path.
     */
    public static function stored(?string $url): ?string
    {
        $u = trim((string) $url);
        if ($u === '') return null;
        if (preg_match('#/media/f\b#', $u) && preg_match('/[?&]p=([A-Za-z0-9_-]+)/', $u, $m)) {
            $rel = base64_decode(strtr($m[1], '-_', '+/') . str_repeat('=', (4 - strlen($m[1]) % 4) % 4), true);
            if ($rel === false || str_contains($rel, '..')) return null;
            return '/storage/' . ltrim($rel, '/');
        }
        if (preg_match('#^https?://#i', $u)) {
            $host = parse_url($u, PHP_URL_HOST);
            if (! in_array($host, ['api.kiddietrac.com', 'app.kiddietrac.com'], true)) return null;
            $u = (string) parse_url($u, PHP_URL_PATH);
        }
        return str_contains($u, '..') ? null : $u;
    }

    /** May this agency's menu point at this stored URL? $keep = URLs the week already had (never break old rows). */
    public static function allowed(?string $stored, int $agencyId, array $keep = []): bool
    {
        if (! $stored) return false;
        if (in_array($stored, $keep, true)) return true;
        if (str_starts_with($stored, self::LIB_PREFIX)) {
            return self::libraryByPath($stored) !== null;
        }
        if (str_starts_with($stored, '/storage/photos/menu/' . $agencyId . '/')) return true;
        if (str_starts_with($stored, '/storage/photos/web-demo/') && PublicDemo::isAgency($agencyId)) return true;
        return false;
    }

    /** How often this agency used each library food in the last 180 days: [food_image_id => n]. */
    public static function usage(int $agencyId): array
    {
        return DB::table('menu_items as i')->join('menu_weeks as w', 'w.id', '=', 'i.menu_week_id')
            ->join('centres as c', 'c.id', '=', 'w.centre_id')
            ->where('c.agency_id', $agencyId)->whereNotNull('i.food_image_id')
            ->where('w.week_start', '>=', now()->subDays(180)->toDateString())
            ->groupBy('i.food_image_id')->selectRaw('i.food_image_id, COUNT(*) n')
            ->pluck('n', 'food_image_id')->map(fn ($n) => (int) $n)->all();
    }
}
