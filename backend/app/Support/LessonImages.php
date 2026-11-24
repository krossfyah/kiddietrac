<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * PICTURES FOR LESSON PLANS (2026-11-02, Anthony: "for lesson plans add image as well to support the lesson plan -
 * use AI to look it up and include in the daily parent summary and on the educator/parent view in the app").
 *
 * The same shape as the menu's food photos (App\Support\FoodPhotos), with one difference: there is no fixed library
 * of lessons, so the picture is LOOKED UP.
 *
 *  1. SEARCH TERMS. With the AI Bundle (AiBundle::on) the AI turns an activity's title / notes / the week's theme into
 *     one or two concrete things to photograph ("leaf rubbing" -> "autumn leaves"). It is given only that text, never a
 *     child or a family. Without the bundle a plain keyword rule does the same job (keywords()).
 *  2. LOOK-UP. Wikimedia Commons, openly licensed files only: CC0, public domain, or CC BY (attribution stored and
 *     shown). Share-alike, non-commercial and no-derivatives licences are skipped, as is anything with a Restrictions
 *     note (personality rights, trademarks) and anything whose title, description or categories mention people.
 *     Openverse was the first choice in the brief, but its anonymous API answers in ~40 s, which no editor can wait for.
 *     The AI (bundle only) then chooses among the candidates by title / description, or says none fits.
 *  3. STORE. The chosen file is downloaded once, cropped to 4:3 and saved as WebP (640x480 + a 200x150 thumb) in the
 *     PUBLIC folder /storage/lesson-library/ (stock pictures, no people), with absolute https URLs, its source page,
 *     creator and licence. lesson_images is keyed by search term, so a theme is never fetched twice; a term that found
 *     nothing is remembered as 'none' for 30 days.
 *
 * On a plan: each activity in lesson_plans.plan_data may carry image_url, thumb_url, image_source, lesson_image_id;
 * the plan itself (the theme's cover) has the same in columns. image_source: library | upload | auto | none.
 * 'none' = removed on purpose and never refilled. Agencies can upload their own under /storage/photos/lesson/<agency>/.
 * Agency setting: agencies.settings.lesson_auto_images (default on).
 */
final class LessonImages
{
    public const LIB_PREFIX = '/storage/lesson-library/';
    public const UA = 'KiddieTrac/1.0 (https://kiddietrac.com; lesson-plan pictures)';
    private const COMMONS = 'https://commons.wikimedia.org/w/api.php';
    private const NONE_DAYS = 30;

    /** Words that say what KIND of activity it is, not what it is about. */
    private const STOP = ['a', 'an', 'the', 'and', 'or', 'of', 'to', 'for', 'in', 'on', 'at', 'with', 'by', 'from', 'our', 'we',
        'will', 'us', 'my', 'your', 'their', 'it', 'is', 'are', 'be', 'about', 'into', 'up', 'out', 'all', 'some', 'new', 'own',
        'time', 'circle', 'activity', 'activities', 'play', 'playing', 'make', 'making', 'made', 'craft', 'crafts', 'art', 'arts',
        'centre', 'center', 'station', 'stations', 'group', 'small', 'large', 'big', 'free', 'morning', 'afternoon', 'today',
        'week', 'day', 'daily', 'discussion', 'talk', 'talking', 'learn', 'learning', 'explore', 'exploring', 'exploration',
        'practice', 'practise', 'practicing', 'practising', 'session', 'game', 'games', 'fun', 'simple', 'easy', 'kids',
        'children', 'child', 'toddler', 'toddlers', 'infant', 'infants', 'preschool', 'class', 'room', 'theme', 'themed',
        'intro', 'introduction', 'review', 'let', 'lets', 'using', 'use', 'show', 'tell', 'share', 'sharing', 'reading',
        'read', 'listen', 'listening', 'watch', 'watching', 'see', 'look', 'looking', 'find', 'finding', 'hunt', 'build',
        'building', 'create', 'creating', 'crew', 'team', 'helpers', 'helper', 'do', 'doing', 'try', 'trying', 'go', 'going', 'take', 'taking', 'get', 'have'];

    /** Activities that are about a thing even though they never name it. */
    private const HINTS = [
        'outdoor play' => 'playground', 'outside play' => 'playground', 'gross motor' => 'playground slide',
        'story' => 'picture books', 'storytime' => 'picture books', 'library' => 'picture books', 'book' => 'picture books',
        'music' => 'musical instruments', 'song' => 'musical instruments', 'songs' => 'musical instruments', 'singing' => 'musical instruments',
        'dance' => 'musical instruments', 'movement' => 'musical instruments',
        'painting' => 'paint brushes', 'paint' => 'paint brushes', 'drawing' => 'crayons', 'colouring' => 'crayons', 'coloring' => 'crayons',
        'playdough' => 'play dough', 'play dough' => 'play dough', 'clay' => 'modelling clay', 'blocks' => 'wooden toy blocks',
        'block' => 'wooden toy blocks', 'lego' => 'toy bricks', 'puzzle' => 'jigsaw puzzle', 'puzzles' => 'jigsaw puzzle',
        'sensory' => 'sensory bin', 'sand' => 'sand toys', 'water play' => 'water table toys', 'yoga' => 'yoga mat',
        'gardening' => 'seedlings', 'garden' => 'vegetable garden', 'baking' => 'baking cookies', 'cooking' => 'kitchen utensils',
        'counting' => 'counting blocks', 'numbers' => 'wooden numbers', 'letters' => 'alphabet blocks', 'alphabet' => 'alphabet blocks',
        'shapes' => 'geometric shapes', 'colours' => 'colour pencils', 'colors' => 'colour pencils', 'weather' => 'clouds sky',
        'calendar' => 'wall calendar', 'snack' => 'fruit bowl', 'tummy' => 'baby toys', 'rattle' => 'baby rattle',
        'bug' => 'ladybird', 'bugs' => 'ladybird', 'insect' => 'ladybird', 'insects' => 'ladybird',
    ];

    /** Nothing honest to picture. */
    private const SKIP = ['nap', 'rest', 'quiet time', 'transition', 'clean up', 'cleanup', 'tidy', 'diaper', 'diapering', 'toilet',
        'potty', 'washroom', 'bathroom', 'hand washing', 'arrival', 'departure', 'dismissal', 'pickup', 'pick up', 'drop off',
        'lunch', 'breakfast', 'dinner', 'attendance', 'sign in', 'sign out'];

    /** Anything that suggests people in the picture, or something unsuitable for a childcare email. */
    private const REJECT = '/\b(child|children|kid|kids|girl|girls|boy|boys|baby|babies|toddler|toddlers|infant|people|person|persons|'
        . 'man|men|woman|women|portrait|portraits|face|faces|selfie|student|students|pupil|pupils|teacher|teachers|family|families|'
        . 'crowd|human|humans|hand|hands|finger|fingers|arm|arms|foot|feet|leg|legs|player|players|athlete|actor|actress|wedding|nude|naked|nudity|erotic|sexy|bikini|'
        . 'war|weapon|weapons|gun|guns|rifle|military|army|soldier|soldiers|dead|death|corpse|blood|hunting|hunted|slaughter|'
        . 'cigarette|smoking|alcohol|beer|wine|logo|logos|trademark|coat of arms|flag|map|maps|diagram|chart|graph|stamp|'
        . 'banknote|coin|poster|advertisement|screenshot|text|signature|manuscript|newspaper|grave|cemetery|church|temple|'
        . 'mosque|politician|president|minister|protest|police|prison|accident|injury|disease|medical|surgery|rotten|rotting|putrid|mould|mold|mouldy|moldy|decay|decaying|trash|garbage|litter|dirty)\b/i';

    /** What the AI is asked (constants so a test can run them without an agency). */
    public const TERMS_PROMPT = 'You choose picture search terms for activities on a childcare centre\'s weekly lesson plan. For each numbered '
            . 'activity give one or two SHORT English search terms (1-3 words each) naming a concrete OBJECT, ANIMAL, PLANT, '
            . 'FOOD, MATERIAL or NATURE SCENE that would illustrate it in a free photo library such as Wikimedia Commons '
            . '(e.g. "Leaf rubbing" -> "autumn leaves"; "Counting with bears" -> "teddy bear counters"; "Story: The Very Hungry '
            . 'Caterpillar" -> "caterpillar"). Never people, children, faces, hands, brands, logos or copyrighted characters. '
            . 'Routine care with nothing to picture (nap, toileting, transitions, sign-in) gets an empty list. '
            . 'Answer ONLY with JSON: {"1":["term","term"],"2":[]}.';

    public const PICK_PROMPT = 'You pick the picture that best illustrates an activity on a childcare centre\'s lesson plan, from files in a '
            . 'free photo library, judging only by their titles and descriptions. The picture is shown to parents of young '
            . 'children. Refuse anything that may show people, children, faces or hands, anything with text, maps, logos, '
            . 'diagrams, museum labels, or anything frightening or unsuitable for small children. Prefer a clear, cheerful '
            . 'picture of the object, animal, plant or scene itself. Answer ONLY with JSON {"pick": n} using the file number, '
            . 'or {"pick": 0} when none of them fits honestly.';

    // ── settings ──────────────────────────────────────────────────────────────────────────────

    /** "Add pictures automatically" - on unless the agency switched it off. */
    public static function autoOn(?int $agencyId): bool
    {
        if (! $agencyId) return false;
        $s = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $a = is_string($s) ? (json_decode($s, true) ?: []) : (array) $s;
        return ($a['lesson_auto_images'] ?? true) !== false;
    }

    public static function setAuto(int $agencyId, bool $on): void
    {
        $s = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $a = is_string($s) ? (json_decode($s, true) ?: []) : (array) $s;
        $a['lesson_auto_images'] = $on;
        DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($a)]);
    }

    /** The agency a plan row belongs to (room plan -> its centre; centre plan -> the centre). */
    public static function agencyForPlan(object $plan): ?int
    {
        $centreId = $plan->centre_id ?? null;
        if (! $centreId && ! empty($plan->room_id)) {
            $centreId = DB::table('rooms')->where('id', $plan->room_id)->value('centre_id');
        }
        $a = $centreId ? DB::table('centres')->where('id', $centreId)->value('agency_id') : null;
        return $a ? (int) $a : null;
    }

    // ── search terms ──────────────────────────────────────────────────────────────────────────

    public static function norm(?string $s): string
    {
        $s = Str::ascii(mb_strtolower(trim((string) $s)));
        $s = preg_replace("/'s\b/", '', $s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /** True when the activity is routine care with nothing to picture (nap, transition, lunch). */
    public static function skippable(string $title): bool
    {
        $t = ' ' . self::norm($title) . ' ';
        foreach (self::SKIP as $w) {
            if (str_contains($t, ' ' . $w . ' ')) {
                // "Nap" is skipped; "Nap time story" is still about a story.
                $rest = trim(str_replace(' ' . $w . ' ', ' ', $t));
                $words = array_diff(explode(' ', $rest), self::STOP);
                if (! array_filter($words)) return true;
            }
        }
        return false;
    }

    /** -ing words that are things, not activities. */
    private const ING_NOUNS = ['spring', 'string', 'strings', 'swing', 'swings', 'ceiling', 'pudding', 'stuffing', 'icing',
        'frosting', 'ring', 'rings', 'king', 'wing', 'wings', 'sling', 'bling', 'dumpling', 'dumplings', 'seedling', 'seedlings',
        'sapling', 'saplings', 'duckling', 'ducklings', 'gosling', 'building', 'buildings', 'painting', 'paintings', 'drawing', 'drawings'];

    /**
     * The keyword rule (no AI). An activity is usually "<thing> <doing>" ("Leaf rubbing", "Pumpkin counting",
     * "Apple tasting"), so the -ing words are dropped and the THINGS are searched: both of the last two together
     * first (strict - see find()), then each on its own, last first (English puts the head noun last:
     * "watercolour sunflowers"), plus a hint for activities about an unnamed thing ("Story time" -> picture books).
     * Up to three terms, most specific first.
     *
     * @return string[]
     */
    public static function keywords(string $title, ?string $notes = null, ?string $theme = null): array
    {
        if (self::skippable($title)) return [];
        $t = self::norm($title);
        $words = array_values(array_filter(explode(' ', $t), fn ($w) => $w !== '' && ! ctype_digit($w) && ! in_array($w, self::STOP, true) && strlen($w) > 2));
        $nouns = array_values(array_filter($words, fn ($w) => ! (strlen($w) >= 6 && str_ends_with($w, 'ing') && ! in_array($w, self::ING_NOUNS, true))));
        $terms = [];
        $hint = null;
        foreach (self::HINTS as $k => $v) {
            if (preg_match('/\b' . preg_quote($k, '/') . '\b/', $t)) { $hint = $v; break; }
        }
        if (count($nouns) >= 2) {
            $terms[] = implode(' ', array_slice($nouns, -2));
        }
        if ($nouns) {
            $terms[] = $nouns[count($nouns) - 1];
            if (count($nouns) >= 2) $terms[] = $nouns[0];
        }
        if ($hint) array_splice($terms, min(1, count($terms)), 0, [$hint]);
        if (! $terms && $theme) {
            $tw = array_values(array_filter(explode(' ', self::norm($theme)), fn ($w) => $w !== '' && ! in_array($w, self::STOP, true) && strlen($w) > 2));
            if ($tw) $terms[] = implode(' ', array_slice($tw, -2));
        }
        $out = [];
        foreach ($terms as $x) { $x = trim($x); if ($x !== '' && ! in_array($x, $out, true) && ! preg_match(self::REJECT, $x)) $out[] = $x; }
        return array_slice($out, 0, 3);
    }

    /**
     * AI search terms for several activities in ONE call (AI Bundle). Each item: ['title'=>, 'notes'=>?].
     * Returns [index => string[]] — an empty list means "nothing to picture"; a missing index means the AI did not
     * answer for it (the caller falls back to keywords()). Null when the AI could not be asked at all.
     *
     * @return array<int,string[]>|null
     */
    public static function aiTerms(array $items, ?string $theme, int $agencyId): ?array
    {
        if (! $items || ! AiBundle::on($agencyId)) return null;
        $out = []; $ask = [];
        foreach ($items as $i => $it) {
            $k = 'lesson-img-terms:v1:' . md5(self::norm($it['title'] ?? '') . '|' . self::norm($it['notes'] ?? '') . '|' . self::norm($theme));
            $c = Cache::get($k);
            if (is_array($c)) { $out[$i] = $c; } else { $ask[$i] = $it + ['_k' => $k]; }
        }
        if (! $ask) return $out;

        $list = [];
        $n = 0; $map = [];
        foreach ($ask as $i => $it) {
            $n++; $map[$n] = $i;
            $list[] = $n . '. ' . mb_substr(trim((string) $it['title']), 0, 160)
                . (trim((string) ($it['notes'] ?? '')) !== '' ? ' — ' . mb_substr(trim((string) $it['notes']), 0, 200) : '');
        }
        $system = self::TERMS_PROMPT;
        $user = ($theme ? "This week's theme: " . mb_substr($theme, 0, 160) . "\n\n" : '') . "ACTIVITIES:\n" . implode("\n", $list);
        $json = self::askAi($system, $user, $agencyId, 600);
        if ($json === null) return $out ?: null;
        foreach ($map as $num => $i) {
            $v = $json[(string) $num] ?? null;
            if (! is_array($v)) continue;
            $terms = [];
            foreach ($v as $t) {
                $t = self::norm(is_string($t) ? $t : '');
                if ($t !== '' && strlen($t) <= 60 && ! preg_match(self::REJECT, $t) && ! in_array($t, $terms, true)) $terms[] = $t;
            }
            $terms = array_slice($terms, 0, 2);
            $out[$i] = $terms;
            Cache::put($ask[$i]['_k'], $terms, now()->addDays(90));
        }
        return $out;
    }

    /** One Anthropic call that must answer with a JSON object. Null on any failure (never throws). */
    private static function askAi(string $system, string $user, int $agencyId, int $maxTokens): ?array
    {
        $key = config('services.anthropic.key') ?: config('services.anthropic.api_key');
        if (! $key) return null;
        UsageMeter::forAgency($agencyId);
        try {
            $r = Http::timeout(30)->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => config('services.anthropic.model') ?: 'claude-haiku-4-5-20251001',
                    'max_tokens' => $maxTokens,
                    'system' => $system,
                    'messages' => [['role' => 'user', 'content' => $user]],
                ]);
        } catch (\Throwable $e) {
            Log::warning('lesson images AI: no answer', ['agency' => $agencyId, 'error' => $e->getMessage()]);
            return null;
        }
        if (! $r->successful()) {
            Log::warning('lesson images AI failed', ['agency' => $agencyId, 'status' => $r->status(), 'body' => mb_substr($r->body(), 0, 300)]);
            return null;
        }
        $text = (string) (AiConfig::text($r->json()) ?? '');
        $j = preg_match('/\{.*\}/s', $text, $m) ? json_decode($m[0], true) : null;
        return is_array($j) ? $j : null;
    }

    // ── look-up ───────────────────────────────────────────────────────────────────────────────

    /**
     * Candidates for a term from Wikimedia Commons: openly licensed, no people, scored. Not downloaded.
     *
     * @return array<int,array{key:string,title:string,thumb:string,full:string,page:string,creator:string,licence:string,licence_url:?string,attribution_required:bool,desc:string,score:int}>
     */
    public static function candidates(string $term, int $limit = 12): array
    {
        $term = self::norm($term);
        if ($term === '') return [];
        $ck = 'lesson-img-cand:v2:' . md5($term);
        $hit = Cache::get($ck);
        if (is_array($hit)) return array_slice($hit, 0, $limit);

        try {
            $r = Http::timeout(15)->withHeaders(['User-Agent' => self::UA])->get(self::COMMONS, [
                'action' => 'query', 'format' => 'json', 'formatversion' => 2,
                'generator' => 'search', 'gsrsearch' => $term . ' filetype:bitmap|drawing', 'gsrnamespace' => 6, 'gsrlimit' => 30,
                'prop' => 'imageinfo', 'iiprop' => 'url|size|mime|extmetadata', 'iiurlwidth' => 800,
                'iiextmetadatafilter' => 'LicenseShortName|LicenseUrl|Artist|Restrictions|Categories|ObjectName|ImageDescription|AttributionRequired|UsageTerms',
            ]);
        } catch (\Throwable $e) {
            Log::info('lesson images: Commons search failed', ['term' => $term, 'error' => $e->getMessage()]);
            return [];
        }
        if (! $r->successful()) return [];
        $pages = $r->json('query.pages') ?: [];
        $tw = array_filter(explode(' ', $term), fn ($w) => strlen($w) > 2);
        $out = [];
        foreach ($pages as $p) {
            $c = self::candidate($p, $tw);
            if ($c) $out[] = $c;
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);
        Cache::put($ck, $out, now()->addDays(7));
        return array_slice($out, 0, $limit);
    }

    /**
     * The lead picture of the English Wikipedia article for a term ("Pumpkin", "Crayon", "Ladybird"): the canonical
     * picture of the thing, chosen by editors - far better than search order for a plain noun. Only when the file is
     * on Commons and passes the same licence / subject checks (a non-free lead image hosted on Wikipedia itself is not
     * found on Commons and is skipped). Disambiguation pages have no lead image, so "Bug" falls through to search.
     */
    public static function wikiLead(string $term): ?array
    {
        $term = self::norm($term);
        if ($term === '' || substr_count($term, ' ') > 2) return null;
        $ck = 'lesson-img-lead:v1:' . md5($term);
        $hit = Cache::get($ck);
        if (is_array($hit)) return $hit ?: null;
        $out = [];
        try {
            $r = Http::timeout(10)->withHeaders(['User-Agent' => self::UA])->get('https://en.wikipedia.org/w/api.php', [
                'action' => 'query', 'format' => 'json', 'formatversion' => 2, 'redirects' => 1,
                'prop' => 'pageimages|pageprops', 'piprop' => 'name', 'ppprop' => 'disambiguation', 'titles' => ucfirst($term),
            ]);
            $pg = $r->successful() ? ($r->json('query.pages.0') ?: []) : [];
            $file = (! empty($pg['pageimage']) && ! isset($pg['pageprops']['disambiguation'])) ? (string) $pg['pageimage'] : '';
            if ($file !== '') {
                $c = Http::timeout(10)->withHeaders(['User-Agent' => self::UA])->get(self::COMMONS, [
                    'action' => 'query', 'format' => 'json', 'formatversion' => 2, 'titles' => 'File:' . $file,
                    'prop' => 'imageinfo', 'iiprop' => 'url|size|mime|extmetadata', 'iiurlwidth' => 800,
                    'iiextmetadatafilter' => 'LicenseShortName|LicenseUrl|Artist|Restrictions|Categories|ObjectName|ImageDescription|AttributionRequired|UsageTerms',
                ]);
                $cp = $c->successful() ? ($c->json('query.pages.0') ?: null) : null;
                $cand = (is_array($cp) && empty($cp['missing'])) ? self::candidate($cp, []) : null;
                if ($cand) $out = $cand + ['lead' => true, 'name_hits' => 9, 'score' => max(30, $cand['score'])];
            }
        } catch (\Throwable $e) {
            return null;   // not cached: try again next time
        }
        Cache::put($ck, $out, now()->addDays(7));
        return $out ?: null;
    }

    /** One Commons search result -> a candidate, or null when its licence, size or subject rules it out. */
    private static function candidate(array $p, array $termWords): ?array
    {
        $ii = $p['imageinfo'][0] ?? null;
        if (! $ii || empty($ii['thumburl'])) return null;
        $mime = (string) ($ii['mime'] ?? '');
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml', 'image/gif', 'image/tiff'], true)) return null;
        $m = $ii['extmetadata'] ?? [];
        $g = fn ($k) => trim(html_entity_decode(strip_tags((string) ($m[$k]['value'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($g('Restrictions') !== '') return null;
        $lic = self::licence($g('LicenseShortName'));
        if (! $lic) return null;

        $w = (int) ($ii['width'] ?? 0); $h = (int) ($ii['height'] ?? 0);
        $svg = $mime === 'image/svg+xml';
        if (! $svg && ($w < 480 || $h < 320)) return null;
        $ratio = $h > 0 ? $w / $h : 1;
        if ($ratio < 0.6 || $ratio > 2.4) return null;   // banners and strips crop badly

        $title = preg_replace('/^File:/', '', (string) ($p['title'] ?? ''));
        $name = preg_replace('/\.[a-z0-9]{2,5}$/i', '', $title);
        $desc = mb_substr($g('ImageDescription') ?: $g('ObjectName'), 0, 240);
        $cats = mb_substr($g('Categories'), 0, 400);
        $hay = mb_strtolower($name . ' ' . $desc . ' ' . str_replace('|', ' ', $cats));
        if (preg_match(self::REJECT, $hay)) return null;

        $score = 0; $nameHits = 0;
        $nameLow = mb_strtolower($name);
        foreach ($termWords as $tw) {
            $stem = strlen($tw) > 4 ? rtrim(preg_replace('/(es|s)$/', '', $tw), 'e') : $tw;   // pinecones ~ pinecone
            if (preg_match('/\b' . preg_quote($stem, '/') . '/i', $nameLow)) { $score += 12; $nameHits++; }
            elseif (preg_match('/\b' . preg_quote($stem, '/') . '/i', $hay)) $score += 6;
        }
        if ($score === 0 && $termWords) return null;   // the search engine matched something we cannot see; do not guess
        // Museum objects, catalogue numbers and interiors: a picture OF an artefact, not of the thing itself.
        if (preg_match('/\b(met|museum|musee|gallery|collection|accession|relief|reliefs|fragment|sculpture|statue|lattice|ornament|engraving|etching|lithograph|woodcut|plate|folio|specimen|herbarium|interior|interiors|calendar|catalogue|catalog|packaging|label|shop|store|market|restaurant)\b/i', $nameLow)) $score -= 10;
        if (preg_match('/\b[a-z]{2,5}\d{3,}\b|\b\d{4}\.\d+/i', $nameLow)) $score -= 6;
        if ($svg || preg_match('/\b(illustration|clipart|clip art|drawing|cartoon|icon|vector)\b/i', $hay)) $score += 4;
        if ($ratio >= 1.15 && $ratio <= 1.6) $score += 3;
        if ($w >= 1200) $score += 2;
        if (preg_match('/\b(quality images|featured pictures|valued images)\b/i', $cats)) $score += 3;
        if (preg_match('/\d{6,}|img_|dsc_|dscn|p\d{7}/i', $nameLow)) $score -= 2;   // camera roll names say little

        $creator = mb_substr(preg_replace('/\s+/', ' ', $g('Artist')), 0, 120);
        return [
            'key' => 'commons:' . $title,
            'title' => mb_substr($name, 0, 160),
            'thumb' => (string) $ii['thumburl'],
            'full' => (string) ($ii['thumburl'] ?? $ii['url']),
            'page' => (string) ($ii['descriptionurl'] ?? ('https://commons.wikimedia.org/wiki/' . rawurlencode('File:' . $title))),
            'creator' => $creator,
            'licence' => $lic['label'],
            'licence_url' => $g('LicenseUrl') ?: $lic['url'],
            'attribution_required' => $lic['by'],
            'desc' => $desc,
            'score' => $score,
            'name_hits' => $nameHits,
        ];
    }

    /**
     * A file a person chose in the picker ("commons:<File title>"): fetched again so its licence and subject are checked
     * here, not taken from the browser, then stored. Null when it no longer passes.
     */
    public static function storeKey(string $key): ?array
    {
        if (! str_starts_with($key, 'commons:')) return null;
        $title = trim(substr($key, 8));
        if ($title === '' || strlen($title) > 240) return null;
        $term = mb_substr('file ' . self::norm(preg_replace('/\.[a-z0-9]{2,5}$/i', '', $title)), 0, 120);
        $row = DB::table('lesson_images')->where('term', self::norm($term))->where('status', 'ok')->first();
        if ($row) return (array) $row;
        try {
            $r = Http::timeout(15)->withHeaders(['User-Agent' => self::UA])->get(self::COMMONS, [
                'action' => 'query', 'format' => 'json', 'formatversion' => 2, 'titles' => 'File:' . $title,
                'prop' => 'imageinfo', 'iiprop' => 'url|size|mime|extmetadata', 'iiurlwidth' => 800,
                'iiextmetadatafilter' => 'LicenseShortName|LicenseUrl|Artist|Restrictions|Categories|ObjectName|ImageDescription|AttributionRequired|UsageTerms',
            ]);
        } catch (\Throwable $e) {
            return null;
        }
        $p = ($r->successful() ? ($r->json('query.pages') ?: []) : [])[0] ?? null;
        $c = is_array($p) ? self::candidate($p, []) : null;
        return $c ? self::store($c, $term) : null;
    }

    /** CC0 / public domain / CC BY only. Share-alike, non-commercial and no-derivatives are refused. */
    public static function licence(string $short): ?array
    {
        $s = strtolower(trim($short));
        if ($s === '') return null;
        if (preg_match('/\b(nc|nd|sa|non-commercial|noncommercial|no derivatives|share ?alike|gfdl|fal|art libre)\b/', $s)) return null;
        if (preg_match('/^cc0\b|cc-zero|cc zero/', $s)) return ['label' => 'CC0', 'url' => 'https://creativecommons.org/publicdomain/zero/1.0/', 'by' => false];
        if (preg_match('/^(public domain|pd\b|pd-|pdm)/', $s)) return ['label' => 'Public domain', 'url' => 'https://creativecommons.org/publicdomain/mark/1.0/', 'by' => false];
        if (preg_match('/^cc[ -]by[ -]?(\d\.\d)?\s*$/', $s, $m)) {
            $v = $m[1] ?? '4.0';
            return ['label' => 'CC BY ' . $v, 'url' => 'https://creativecommons.org/licenses/by/' . $v . '/', 'by' => true];
        }
        return null;
    }

    /**
     * The AI chooses among candidates by what their titles and descriptions say (AI Bundle). Returns the chosen index,
     * -1 for "none fits", or null when the AI could not be asked.
     */
    public static function aiPick(array $cands, string $about, int $agencyId): ?int
    {
        if (! $cands || ! AiBundle::on($agencyId)) return null;
        $lines = [];
        foreach (array_values($cands) as $i => $c) {
            $lines[] = ($i + 1) . '. ' . $c['title'] . ($c['desc'] ? ' — ' . mb_substr($c['desc'], 0, 160) : '');
        }
        $system = self::PICK_PROMPT;
        $j = self::askAi($system, 'ACTIVITY: ' . mb_substr($about, 0, 300) . "\n\nFILES:\n" . implode("\n", $lines), $agencyId, 120);
        if ($j === null || ! isset($j['pick'])) return null;
        $n = (int) $j['pick'];
        return ($n >= 1 && $n <= count($cands)) ? $n - 1 : -1;
    }

    /**
     * The stored picture for a search term, looking it up on a cache miss. Null = nothing suitable.
     * $agencyId only decides whether the AI may choose among candidates (AiBundle::on).
     */
    public static function find(string $term, ?int $agencyId = null, ?string $about = null): ?array
    {
        $key = self::norm($term);
        if ($key === '' || strlen($key) > 120) return null;
        $row = DB::table('lesson_images')->where('term', $key)->first();
        if ($row && $row->status === 'ok' && $row->url) {
            return self::$avoid === null ? (array) $row : self::fresh($key, (array) $row, $agencyId, $about);
        }
        if ($row && $row->status === 'none' && $row->updated_at && now()->diffInDays($row->updated_at, true) < self::NONE_DAYS) return null;

        $lock = Cache::lock('lesson-img-find:' . md5($key), 40);
        if (! $lock->get()) return null;   // another request is fetching the same term right now
        try {
            // Strict: every word of the term in the file's name (one word: in the name), and clear of the museum-object penalties.
            $need = count(array_filter(explode(' ', $key), fn ($w) => strlen($w) > 2)) ?: 1;
            $cands = array_values(array_filter(self::candidates($key, 30), fn ($c) => $c['name_hits'] >= $need && $c['score'] >= 14));
            $lead = self::wikiLead($key);
            if ($lead) {
                $cands = array_values(array_filter($cands, fn ($c) => $c['key'] !== $lead['key']));
                array_unshift($cands, $lead);
            }
            $cands = array_slice($cands, 0, 8);
            $pick = null;
            if ($cands) {
                $ai = $agencyId ? self::aiPick($cands, ($about ?: $key) . ' (search: ' . $key . ')', $agencyId) : null;
                if ($ai === -1) { $cands = []; }
                elseif ($ai !== null) { $pick = $cands[$ai]; }
                else { $pick = $cands[0]; }
            }
            $stored = $pick ? self::store($pick, $key) : null;
            if (! $stored) {
                DB::table('lesson_images')->updateOrInsert(['term' => $key], ['status' => 'none', 'updated_at' => now(), 'created_at' => $row->created_at ?? now()]);
                return null;
            }
            return $stored;
        } finally {
            $lock->release();
        }
    }

    /* NEVER THE SAME PICTURE TWICE (2026-11-09, Anthony: "with AI pulling images, writing emails etc etc never repeat it
       self and use unique content - wire this up properly"). One row per term meant every "apple" activity in every
       plan got the same apple. A term now keeps up to VARIANTS pictures (rows "apple", "apple#2" ... "apple#6"), and
       while $avoid is set (fillPlan, the "Find a picture" button) a pick skips every picture the agency's plans used in
       the last AVOID_WEEKS weeks and every one already picked in this run, fetching the next different picture for the
       term when all the stored ones are taken. Only when no other suitable picture exists does a used one come back:
       a repeat is better than an empty card. */
    public const VARIANTS = 6;
    public const AVOID_WEEKS = 8;
    /** @var array{ids: array<int,bool>, urls: array<string,bool>}|null  null = no avoiding (the old one-per-term behaviour) */
    public static ?array $avoid = null;

    /** Start avoiding what this agency's recent plans already show. */
    public static function avoidFor(?int $agencyId): void
    {
        self::$avoid = ['ids' => [], 'urls' => []];
        if (! $agencyId) return;
        try {
            $from = now()->subWeeks(self::AVOID_WEEKS)->toDateString();
            $ids = [];
            $plans = DB::table('lesson_plans')->whereIn('id', self::planIdsForAgency($agencyId, $from) ?: [0])->get(['lesson_image_id', 'plan_data']);
            foreach ($plans as $pl) {
                if ($pl->lesson_image_id) $ids[] = (int) $pl->lesson_image_id;
                $data = json_decode((string) $pl->plan_data, true) ?: [];
                foreach ((array) ($data['days'] ?? []) as $acts) {
                    foreach ((array) $acts as $a) if (is_array($a) && ! empty($a['lesson_image_id'])) $ids[] = (int) $a['lesson_image_id'];
                }
            }
            if ($ids) {
                foreach (DB::table('lesson_images')->whereIn('id', array_unique($ids))->get(['id', 'original_url']) as $r) self::taken((array) $r);
            }
        } catch (\Throwable $e) {
            // Avoiding is a nicety: on any failure pictures are found as before.
        }
    }

    /** Mark a picture as shown, so this run never picks it (or the same file under another term) again. */
    public static function taken(array $img): void
    {
        if (self::$avoid === null) return;
        if (! empty($img['id'])) self::$avoid['ids'][(int) $img['id']] = true;
        if (! empty($img['original_url'])) self::$avoid['urls'][(string) $img['original_url']] = true;
    }

    private static function isTaken(array $img): bool
    {
        return isset(self::$avoid['ids'][(int) ($img['id'] ?? 0)]) || (! empty($img['original_url']) && isset(self::$avoid['urls'][(string) $img['original_url']]));
    }

    /** A picture for $key the agency has not shown lately: a stored variant, else a newly fetched one, else $base. */
    private static function fresh(string $key, array $base, ?int $agencyId, ?string $about): array
    {
        $stem = mb_substr($key, 0, 116);
        $terms = [$key];
        for ($n = 2; $n <= self::VARIANTS; $n++) $terms[] = $stem . '#' . $n;
        $rows = DB::table('lesson_images')->whereIn('term', $terms)->get()->map(fn ($r) => (array) $r)->keyBy('term');
        foreach ($terms as $t) {
            $r = $rows[$t] ?? null;
            if ($r && $r['status'] === 'ok' && $r['url'] && ! self::isTaken($r)) { self::taken($r); return $r; }
        }
        $next = null;
        foreach ($terms as $t) if (! isset($rows[$t])) { $next = $t; break; }
        if ($next === null) return $base;   // every variant is in use: the first one again
        $lock = Cache::lock('lesson-img-find:' . md5($next), 40);
        if (! $lock->get()) return $base;
        try {
            $have = $rows->pluck('original_url')->filter()->flip()->all();
            $need = count(array_filter(explode(' ', $key), fn ($w) => strlen($w) > 2)) ?: 1;
            $cands = array_values(array_filter(self::candidates($key, 30), fn ($c) => $c['name_hits'] >= $need && $c['score'] >= 14
                && ! isset($have[$c['full']]) && ! isset(self::$avoid['urls'][$c['full']])));
            $cands = array_slice($cands, 0, 8);
            if (! $cands) return $base;
            $ai = $agencyId ? self::aiPick($cands, ($about ?: $key) . ' (search: ' . $key . ')', $agencyId) : null;
            if ($ai === -1) return $base;
            $stored = self::store($ai !== null ? $cands[$ai] : $cands[0], $next);
            if (! $stored || $stored['status'] !== 'ok') return $base;
            self::taken($stored);
            return $stored;
        } catch (\Throwable $e) {
            return $base;
        } finally {
            $lock->release();
        }
    }

    /** Download a chosen candidate, crop to 4:3, write WebP + thumb, record it under $term. */
    public static function store(array $c, string $term): ?array
    {
        $term = preg_match('/^(.+)#([2-9])$/', $term, $m) ? self::norm($m[1]) . '#' . $m[2] : self::norm($term);
        // The same file under another term: reuse its files, record this term too.
        $same = DB::table('lesson_images')->where('original_url', $c['full'])->where('status', 'ok')->first();
        if ($same) {
            $fields = (array) $same;
            unset($fields['id'], $fields['term'], $fields['created_at'], $fields['updated_at'], $fields['used']);
            DB::table('lesson_images')->updateOrInsert(['term' => $term], $fields + ['updated_at' => now(), 'created_at' => now()]);
            return (array) DB::table('lesson_images')->where('term', $term)->first();
        }
        try {
            $r = Http::timeout(25)->withHeaders(['User-Agent' => self::UA])->get($c['full']);
        } catch (\Throwable $e) {
            return null;
        }
        if (! $r->successful() || strlen($r->body()) > 15 * 1024 * 1024) return null;
        $files = self::writeWebp($r->body(), 'lesson-library', Str::slug(mb_substr($term, 0, 40)) . '-' . substr(md5($c['full']), 0, 8));
        if (! $files) return null;
        $base = rtrim((string) (config('app.url') ?: 'https://api.kiddietrac.com'), '/');
        $fields = [
            'status' => 'ok', 'provider' => 'commons',
            'url' => $base . $files[0], 'thumb_url' => $base . $files[1],
            'title' => mb_substr($c['title'], 0, 250), 'creator' => mb_substr((string) $c['creator'], 0, 250) ?: null,
            'licence' => $c['licence'], 'licence_url' => $c['licence_url'] ? mb_substr($c['licence_url'], 0, 490) : null,
            'source_url' => mb_substr($c['page'], 0, 490), 'original_url' => mb_substr($c['full'], 0, 990),
            'attribution_required' => (bool) $c['attribution_required'],
            'updated_at' => now(),
        ];
        DB::table('lesson_images')->updateOrInsert(['term' => $term], $fields + ['created_at' => now()]);
        return (array) DB::table('lesson_images')->where('term', $term)->first();
    }

    /**
     * Bytes -> 640x480 + 200x150 WebP (centre crop, white behind transparency) on the public disk under $dir.
     * Returns the two /storage/... paths, or null when the bytes are not a picture GD can read.
     *
     * @return array{0:string,1:string}|null
     */
    public static function writeWebp(string $bytes, string $dir, string $name): ?array
    {
        $im = @imagecreatefromstring($bytes);
        if (! $im) return null;
        $w = imagesx($im); $h = imagesy($im);
        $tw = $w; $th = (int) round($w * 3 / 4);
        if ($th > $h) { $th = $h; $tw = (int) round($h * 4 / 3); }
        $sx = (int) (($w - $tw) / 2); $sy = (int) (($h - $th) / 2);
        $paths = [];
        foreach ([[640, 480, ''], [200, 150, '-thumb']] as [$ow, $oh, $suffix]) {
            $out = imagecreatetruecolor($ow, $oh);
            imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
            imagecopyresampled($out, $im, 0, 0, $sx, $sy, $ow, $oh, $tw, $th);
            ob_start(); imagewebp($out, null, 80); $webp = ob_get_clean();
            imagedestroy($out);
            Storage::disk('public')->put($dir . '/' . $name . $suffix . '.webp', $webp);
            $paths[] = '/storage/' . $dir . '/' . $name . $suffix . '.webp';
        }
        imagedestroy($im);
        return $paths;
    }

    /** A stored picture for one activity: the first term that finds one. */
    public static function forTerms(array $terms, ?int $agencyId, string $about): ?array
    {
        foreach ($terms as $t) {
            $img = self::find((string) $t, $agencyId, $about);
            if ($img) { self::taken($img); return $img + ['_term' => $t]; }
        }
        return null;
    }

    public static function byId(?int $id): ?array
    {
        if (! $id) return null;
        static $memo = [];
        if (! array_key_exists($id, $memo)) {
            $r = DB::table('lesson_images')->where('id', $id)->where('status', 'ok')->first();
            $memo[$id] = $r ? (array) $r : null;
        }
        return $memo[$id];
    }

    /** "Photo: Jane Doe · CC BY 4.0" — what a picture's licence asks us to show (null for our own uploads). */
    public static function credit(?array $img): ?string
    {
        if (! $img) return null;
        $who = trim((string) ($img['creator'] ?? ''));
        if (preg_match('/^unknown/i', $who)) $who = '';
        return trim(($who !== '' ? $who . ' · ' : '') . ($img['licence'] ?? '') . ' · Wikimedia Commons', ' ·');
    }

    // ── on a plan ─────────────────────────────────────────────────────────────────────────────

    /** The picture fields an activity or plan can carry. */
    public const FIELDS = ['image_url', 'thumb_url', 'image_source', 'lesson_image_id'];

    /** May this agency's plan point at this stored URL? $keep = URLs the plan already had (never break old rows). */
    public static function allowed(?string $stored, int $agencyId, array $keep = []): bool
    {
        if (! $stored) return false;
        if (in_array($stored, $keep, true)) return true;
        $path = (string) parse_url($stored, PHP_URL_PATH);
        if (str_starts_with($path, self::LIB_PREFIX)) {
            return DB::table('lesson_images')->where('status', 'ok')
                ->where(fn ($q) => $q->where('url', 'like', '%' . $path)->orWhere('thumb_url', 'like', '%' . $path))->exists();
        }
        if (str_starts_with($stored, '/storage/photos/lesson/' . $agencyId . '/')) return true;
        if (str_starts_with($stored, '/storage/photos/web-demo/') && PublicDemo::isAgency($agencyId)) return true;
        return false;
    }

    /**
     * Clean the picture fields a client sent for one activity (or the plan cover). Returns the fields to store, or
     * null when the client sent no picture fields at all (an older screen, a template, the AI draft) — the caller then
     * keeps whatever the saved plan had for that activity.
     */
    public static function clean(array $in, int $agencyId, array $keep): ?array
    {
        $has = false;
        foreach (self::FIELDS as $f) if (array_key_exists($f, $in)) { $has = true; break; }
        if (! $has && ! empty($in['image'])) {
            // The web-demo seeds wrote a bare "image" thumbnail; keep it as the picture.
            $u = FoodPhotos::stored((string) $in['image']);
            return ($u && self::allowed($u, $agencyId, $keep)) ? ['image_url' => $u, 'thumb_url' => $u, 'image_source' => 'library', 'lesson_image_id' => null] : null;
        }
        if (! $has) return null;
        $src = in_array($in['image_source'] ?? null, ['library', 'upload', 'auto', 'none'], true) ? $in['image_source'] : null;
        if ($src === 'none') return ['image_url' => null, 'thumb_url' => null, 'image_source' => 'none', 'lesson_image_id' => null];
        $url = FoodPhotos::stored($in['image_url'] ?? null);
        $thumb = FoodPhotos::stored($in['thumb_url'] ?? null) ?: $url;
        if (! $url || ! self::allowed($url, $agencyId, $keep) || ($thumb !== $url && ! self::allowed($thumb, $agencyId, $keep))) {
            return ['image_url' => null, 'thumb_url' => null, 'image_source' => null, 'lesson_image_id' => null];
        }
        // Library pictures are stored absolute (they load in the app shells and in email); uploads stay relative and
        // are signed on the way out.
        $abs = fn ($u) => str_starts_with($u, self::LIB_PREFIX) ? rtrim((string) (config('app.url') ?: 'https://api.kiddietrac.com'), '/') . $u : $u;
        $id = isset($in['lesson_image_id']) && is_numeric($in['lesson_image_id']) ? (int) $in['lesson_image_id'] : null;
        if ($id && ! self::byId($id)) $id = null;
        return ['image_url' => $abs($url), 'thumb_url' => $abs($thumb), 'image_source' => $src ?: 'library', 'lesson_image_id' => $id];
    }

    /** Every picture URL a saved plan already uses (activities + cover) — allowed again on save. */
    public static function urlsIn(?array $days, ?object $plan = null): array
    {
        $u = [];
        foreach ((array) $days as $acts) {
            foreach ((array) $acts as $a) {
                foreach (['image_url', 'thumb_url', 'image'] as $f) if (! empty($a[$f])) $u[] = FoodPhotos::stored((string) $a[$f]);
            }
        }
        if ($plan) foreach (['image_url'] as $f) if (! empty($plan->{$f})) $u[] = FoodPhotos::stored((string) $plan->{$f});
        return array_values(array_filter(array_unique($u)));
    }

    /** Add display-only credit fields to every activity that has a looked-up picture. */
    public static function decorate(array $days): array
    {
        foreach ($days as $d => $acts) {
            if (! is_array($acts)) continue;
            foreach ($acts as $i => $a) {
                if (! is_array($a)) continue;
                if (empty($a['image_url']) && ! empty($a['image'])) { $a['image_url'] = $a['image']; $a['thumb_url'] = $a['image']; }
                $img = ! empty($a['image_url']) ? self::byId(isset($a['lesson_image_id']) ? (int) $a['lesson_image_id'] : null) : null;
                $a['image_credit'] = $img ? self::credit($img) : null;
                $a['image_credit_url'] = $img ? ($img['source_url'] ?? null) : null;
                $a['image_credit_required'] = $img ? (bool) ($img['attribution_required'] ?? false) : false;
                $acts[$i] = $a;
            }
            $days[$d] = $acts;
        }
        return $days;
    }

    /** The plan's cover for an API response. */
    public static function cover(?object $plan): ?array
    {
        if (! $plan) return null;
        $img = self::byId(isset($plan->lesson_image_id) ? (int) $plan->lesson_image_id : null);
        return [
            'image_url' => $plan->image_url ?? null,
            'thumb_url' => ! empty($plan->image_url) ? ($img['thumb_url'] ?? ($plan->image_url ?? null)) : null,
            'image_source' => $plan->image_source ?? null,
            'lesson_image_id' => $plan->lesson_image_id ?? null,
            'image_credit' => (! empty($plan->image_url) && $img) ? self::credit($img) : null,
            'image_credit_url' => (! empty($plan->image_url) && $img) ? ($img['source_url'] ?? null) : null,
            'image_credit_required' => (! empty($plan->image_url) && $img) ? (bool) ($img['attribution_required'] ?? false) : false,
        ];
    }

    /** Does this activity (or cover) still want a picture? */
    public static function wants(array $a): bool
    {
        return trim((string) ($a['image_url'] ?? '')) === '' && trim((string) ($a['image'] ?? '')) === ''
            && ($a['image_source'] ?? null) !== 'none';
    }

    /**
     * Fill the pictures a saved plan is missing. Never touches a picture someone chose, uploaded or removed.
     * $apply = false counts only (no network, no writes). Returns ['missing'=>n, 'filled'=>n, 'left'=>n].
     */
    public static function fillPlan(int $planId, bool $apply = true, int $budgetSeconds = 25, ?int $limit = null): array
    {
        $plan = DB::table('lesson_plans')->where('id', $planId)->first();
        if (! $plan) return ['missing' => 0, 'filled' => 0, 'left' => 0];
        $agencyId = self::agencyForPlan($plan);
        $data = json_decode((string) $plan->plan_data, true) ?: [];
        $days = is_array($data['days'] ?? null) ? $data['days'] : [];

        $want = [];   // [ [day, idx, title, notes] ]
        foreach ($days as $d => $acts) {
            foreach ((array) $acts as $i => $a) {
                if (! is_array($a) || trim((string) ($a['title'] ?? '')) === '' || ! self::wants($a)) continue;
                if (self::skippable((string) $a['title'])) continue;
                $want[] = [$d, $i, trim((string) $a['title']), trim((string) ($a['notes'] ?? ''))];
            }
        }
        $coverWanted = trim((string) ($plan->theme ?? '')) !== '' && trim((string) ($plan->image_url ?? '')) === '' && ($plan->image_source ?? null) !== 'none';
        $missing = count($want) + ($coverWanted ? 1 : 0);
        if (! $apply || ! $missing || ! $agencyId) return ['missing' => $missing, 'filled' => 0, 'left' => $missing];
        if ($limit !== null) $want = array_slice($want, 0, $limit);

        $t0 = microtime(true);
        $items = array_map(fn ($w) => ['title' => $w[2], 'notes' => $w[3]], $want);
        $outer = self::$avoid;
        if ($outer === null) self::avoidFor($agencyId);   // a batch run (the command) may share one list across plans
        if ($coverWanted) $items['cover'] = ['title' => (string) $plan->theme, 'notes' => ''];
        $ai = self::aiTerms($items, $plan->theme ?? null, $agencyId) ?? [];

        $found = []; $coverImg = null;
        $timedOut = false;
        foreach ($items as $k => $it) {
            if (microtime(true) - $t0 > $budgetSeconds) { $timedOut = true; break; }
            $terms = array_key_exists($k, $ai) ? $ai[$k] : self::keywords($it['title'], $it['notes'], $plan->theme ?? null);
            if ($k === 'cover' && ! $terms) $terms = self::keywords($it['title']);
            $img = $terms ? self::forTerms($terms, $agencyId, $it['title'] . ($it['notes'] ? ' — ' . $it['notes'] : '')) : null;
            if ($k === 'cover') { $coverImg = $img; continue; }
            if ($img) $found[$k] = $img;
        }
        if ($outer === null) self::$avoid = null;

        // Write back onto a FRESH read, only where the activity is still the same one and still wants a picture, so a
        // save that landed while this ran is never undone.
        $filled = 0;
        DB::transaction(function () use ($planId, $want, $found, $coverImg, &$filled) {
            $fresh = DB::table('lesson_plans')->where('id', $planId)->lockForUpdate()->first();
            if (! $fresh) return;
            $data = json_decode((string) $fresh->plan_data, true) ?: [];
            foreach ($found as $k => $img) {
                [$d, $i, $title] = $want[$k];
                $a = $data['days'][$d][$i] ?? null;
                if (! is_array($a) || trim((string) ($a['title'] ?? '')) !== $title || ! self::wants($a)) continue;
                $data['days'][$d][$i] = array_merge($a, ['image_url' => $img['url'], 'thumb_url' => $img['thumb_url'],
                    'image_source' => 'auto', 'lesson_image_id' => (int) $img['id']]);
                $filled++;
            }
            $upd = [];
            if ($found) $upd['plan_data'] = json_encode($data);
            if ($coverImg && trim((string) ($fresh->image_url ?? '')) === '' && ($fresh->image_source ?? null) !== 'none') {
                $upd += ['image_url' => $coverImg['url'], 'image_source' => 'auto', 'lesson_image_id' => (int) $coverImg['id']];
                $filled++;
            }
            if ($upd) DB::table('lesson_plans')->where('id', $planId)->update($upd);   // updated_at untouched: nobody edited it
        });

        // 'left' = still without a picture (nothing suitable, or the time ran out); 'done' = every one was tried.
        return ['missing' => $missing, 'filled' => $filled, 'left' => max(0, $missing - $filled), 'done' => ! $timedOut];
    }

    /** Plan rows of an agency from a date on (room plans via their room's centre, centre plans directly). */
    public static function planIdsForAgency(int $agencyId, string $fromWeek): array
    {
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        if (! $centres) return [];
        $rooms = DB::table('rooms')->whereIn('centre_id', $centres)->pluck('id')->all();
        return DB::table('lesson_plans')->where('week_starting', '>=', $fromWeek)
            ->where(fn ($q) => $q->whereIn('centre_id', $centres)->orWhereIn('room_id', $rooms ?: [0]))
            ->orderBy('week_starting')->pluck('id')->map(fn ($i) => (int) $i)->all();
    }
}
