<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PLAY SPARK (2026-11-24) - on-the-spot play ideas.
 *
 * Anthony asked for a KiddieTrac take on AI activity generators ("review their offering and how can we embed this
 * into kiddietrac"), then: full build, named "Play Spark", and "playspark should only be available when customer
 * opt'd to the AI plan" - so it is part of the AI Bundle (feature key ai_tools: EnforcePlanFeatures routes +
 * FeatureFlagController hashes + AiBundle::on here).
 *
 * What goes to the AI: AGE BANDS AND COUNTS ONLY (never names, birthdays or photos of children), the room's
 * materials, the setting, the week's lesson-plan theme and the agency's framework. A materials photo is read once
 * for the list of objects and is never stored; it is refused if a person is in it.
 *
 * Safety is not left to the AI: check() scans every idea for items that are never allowed in a childcare room and,
 * when under-3s are present, for choking-size items. One retry with the problem named; still unsafe = refused
 * (banned) or flagged "caution" with the warning written into the safety notes (choking - older tiers may use them).
 * The educator must tick the safety check before "We did it" is accepted (PlaySparkController::done).
 */
final class PlaySpark
{
    public const BANDS = [
        'infant' => 'Infants (under 18 months)',
        'toddler' => 'Toddlers (18 months to 3)',
        'preschool' => 'Preschool (3 to 5)',
        'school_age' => 'School-age (6+)',
    ];

    /** Never in a Play Spark idea, at any age. */
    private const BANNED = [
        'button batter(y|ies)' => 'button batteries', 'coin[- ]cell' => 'coin-cell batteries', 'lithium batter' => 'lithium batteries',
        'neodymium|small magnets?|loose magnets?|magnetic beads?' => 'small magnets', 'water beads?|orbeez|gel beads?' => 'water beads',
        'balloons?' => 'balloons', 'plastic (shopping |grocery )?bags?' => 'plastic bags',
        'glass (jars?|bottles?|cups?|beads?|marbles?)' => 'glass', 'knife|knives|box cutter|craft blade|utility blade|razor' => 'blades',
        'hot glue' => 'hot glue', 'candles?|matches|lighters?' => 'open flame', 'bleach|essential oils?' => 'chemicals',
        'boiling|hot water|stove|oven' => 'heat',
    ];

    /** Choking-size or choking-shaped items: not around children under 3. */
    private const CHOKING = [
        'beads?' => 'beads', 'buttons(?! batter)' => 'buttons', 'marbles?' => 'marbles', 'coins?' => 'coins',
        'pebbles?|small stones?|gravel|small rocks?' => 'pebbles and small stones', 'dried beans?|dry beans?|beans|lentils|chickpeas' => 'dried beans',
        'dry pasta|dried pasta|macaroni|penne' => 'dry pasta', 'popcorn' => 'popcorn', 'grapes?' => 'grapes',
        'peanuts?|nuts\b' => 'nuts', 'small balls?|ping[- ]pong|bouncy balls?' => 'small balls', 'pom[- ]?poms?' => 'pom-poms',
        'googly eyes?' => 'googly eyes', 'bottle caps?|bottle lids?' => 'bottle caps', 'sequins?' => 'sequins',
        'push ?pins?|thumbtacks?|paper clips?|staples' => 'pins and clips', 'acorns?|chestnuts?' => 'acorns',
    ];

    /** Food or allergen materials: an allergy reminder is added. */
    private const FOOD = 'flour|play ?dough|playdough|oats|cereal|pasta|rice|milk|peanut|nuts\b|eggs?\b|food colou?ring|cornstarch|corn starch|jell-?o|pudding|bread|crackers?';

    public static function bandOf(?string $dob, string $today): ?string
    {
        if (! $dob) {
            return null;
        }
        try {
            $m = Carbon::parse($dob)->diffInMonths(Carbon::parse($today));
        } catch (\Throwable $e) {
            return null;
        }
        $m = (int) floor(abs($m));

        return $m < 18 ? 'infant' : ($m < 36 ? 'toddler' : ($m < 72 ? 'preschool' : 'school_age'));
    }

    /** Children signed into this room right now (check-in with no later check-out today). */
    public static function presentChildIds(int $roomId): array
    {
        [$from, $to] = AgencyTime::dayRangeForRoom($roomId);

        return DB::table('check_events as ci')
            ->where('ci.room_id', $roomId)->where('ci.event_type', 'check_in')
            ->whereBetween('ci.occurred_at', [$from, $to])->where('ci.occurred_at', '<=', now())
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('check_events as co')
                ->whereColumn('co.child_id', 'ci.child_id')->where('co.event_type', 'check_out')
                ->where('co.occurred_at', '<=', now())
                ->where(fn ($w) => $w->whereColumn('co.occurred_at', '>', 'ci.occurred_at')
                    ->orWhere(fn ($w2) => $w2->whereColumn('co.occurred_at', 'ci.occurred_at')->whereColumn('co.id', '>', 'ci.id'))))
            ->distinct()->pluck('ci.child_id')->map(fn ($v) => (int) $v)->all();
    }

    /** Everything the screen and the AI need about a room. No names. */
    public static function roomContext(int $agencyId, int $roomId): array
    {
        $room = DB::table('rooms')->where('id', $roomId)->first(['id', 'name', 'centre_id']);
        $today = (string) AgencyTime::today($agencyId);
        $present = self::presentChildIds($roomId);
        $kids = $present ? DB::table('children')->whereIn('id', $present)->whereNull('deleted_at')->get(['id', 'date_of_birth']) : collect();
        $bands = array_fill_keys(array_keys(self::BANDS), 0);
        foreach ($kids as $k) {
            $b = self::bandOf($k->date_of_birth, $today);
            if ($b) {
                $bands[$b]++;
            }
        }

        // This week's lesson-plan theme: the room's own plan, else the centre-wide one.
        $monday = Carbon::parse($today)->startOfWeek(Carbon::MONDAY)->toDateString();
        $theme = null;
        try {
            $theme = DB::table('lesson_plans')->where('week_starting', $monday)
                ->where(fn ($q) => $q->where('room_id', $roomId)->orWhere(fn ($q2) => $q2->whereNull('room_id')->where('centre_id', $room->centre_id ?? 0)))
                ->orderByRaw('room_id IS NULL')->value('theme');
        } catch (\Throwable $e) {
        }

        $fw = LearningFrameworks::forAgency($agencyId);

        // Learning areas this room has seen least of lately (present children, else everyone enrolled here).
        $pool = $present ?: DB::table('enrollments')->where('room_id', $roomId)->whereNull('end_date')->pluck('child_id')->map(fn ($v) => (int) $v)->all();
        $counts = array_fill_keys(LearningFrameworks::DOMAINS, 0);
        if ($pool) {
            foreach (DB::table('observations')->whereIn('child_id', $pool)->where('observed_at', '>=', now()->subDays(60))
                ->selectRaw('domain, count(*) n')->groupBy('domain')->get() as $r) {
                if (isset($counts[$r->domain])) {
                    $counts[$r->domain] = (int) $r->n;
                }
            }
        }
        asort($counts);
        $gaps = array_slice(array_keys($counts), 0, 2);

        $materials = DB::table('play_spark_materials')->where('agency_id', $agencyId)->where('centre_id', $room->centre_id ?? 0)
            ->where(fn ($q) => $q->whereNull('room_id')->orWhere('room_id', $roomId))
            ->orderBy('name')->get(['id', 'name', 'room_id']);

        return [
            'room' => ['id' => (int) $roomId, 'name' => $room->name ?? '', 'centre_id' => (int) ($room->centre_id ?? 0)],
            'present' => count($kids),
            'bands' => $bands,
            'theme' => $theme,
            'framework' => ['key' => $fw['key'], 'name' => $fw['name'], 'short' => $fw['short'] ?? $fw['key']],
            'gaps' => array_map(fn ($d) => ['domain' => $d, 'label' => self::domainLabel($d), 'area' => self::areaLabel($fw, $d)], $gaps),
            'materials' => $materials,
        ];
    }

    public static function domainLabel(string $d): string
    {
        return [
            'social_emotional' => 'Social & emotional', 'physical' => 'Physical', 'language_literacy' => 'Language & literacy',
            'cognitive' => 'Thinking & problem-solving', 'creative_arts' => 'Creative arts', 'self_care' => 'Self-care', 'outdoor' => 'Outdoor & nature',
        ][$d] ?? ucfirst(str_replace('_', ' ', $d));
    }

    /** The agency framework's name for a KiddieTrac domain (HDLH "engagement" for cognitive, etc.). */
    public static function areaLabel(array $fw, string $domain): ?string
    {
        $map = LearningFrameworks::DOMAIN_MAP[$fw['key']] ?? [];
        $area = $map[$domain] ?? null;
        if (is_array($area)) {
            $area = $area[0] ?? null;
        }
        foreach ($fw['areas'] ?? [] as $a) {
            if (($a['key'] ?? null) === $area) {
                return $a['label'];
            }
        }

        return null;
    }

    /* ── the AI ─────────────────────────────────────────────────────────────── */

    private static function call(array $payload, int $timeout = 60): array
    {
        $key = (string) config('services.anthropic.key');
        if ($key === '') {
            throw new \RuntimeException('Play Spark is not available right now.');
        }
        $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'])
            ->timeout($timeout)->post('https://api.anthropic.com/v1/messages', $payload + ['model' => config('services.anthropic.model')]);
        if (! $r->successful()) {
            Log::warning('Play Spark AI error', ['status' => $r->status(), 'body' => mb_substr($r->body(), 0, 400)]);
            throw new \RuntimeException(AiConfig::friendly($r->status(), (string) ($r->json('error.message') ?? '')));
        }
        $text = (string) (AiConfig::text($r->json()) ?? '');
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text));
        $j = json_decode($text, true);
        if (! is_array($j)) {
            $s = strpos($text, '{');
            $e = strrpos($text, '}');
            $j = ($s !== false && $e > $s) ? json_decode(substr($text, $s, $e - $s + 1), true) : null;
        }
        if (! is_array($j)) {
            throw new \RuntimeException('Play Spark could not put that idea together. Please try again.');
        }

        return $j;
    }

    private static function systemPrompt(): string
    {
        return "You design play activities for licensed child care programs in Canada and the United States. "
            . "An educator needs an idea right now, for the children in the room. Write in plain, warm Canadian English, "
            . "short sentences, no jargon. Never invent or use child names.\n\n"
            . "Rules:\n"
            . "- Use the materials listed, plus everyday room basics (paper, crayons, markers, tape, scissors for adults, boxes, cushions, scarves). Nothing to buy.\n"
            . "- Write one tier for EACH age band listed, so every child can join the same activity at their own level.\n"
            . "- Never use: button or coin batteries, small magnets, water beads, balloons, plastic bags, glass, blades, hot glue, flames, heat, chemicals.\n"
            . "- If infants or toddlers are in the room, no item smaller than a toilet-paper tube opening anywhere in the activity (beads, buttons, coins, pebbles, dried beans, pom-poms, small balls, bottle caps, grapes, nuts...).\n"
            . "- Infants: sensory, tummy time, reaching, mouthing-safe large items, adult always within arm's reach.\n"
            . "- Match the involvement asked for: 'low' = children lead with light watching, 'guided' = educator sets up and coaches, 'hands_on' = educator plays alongside.\n\n"
            . "Return ONLY JSON, no prose:\n"
            . '{"title":"3-6 words","summary":"1-2 sentences","setting":"indoor|outdoor|either","minutes":20,'
            . '"materials":["..."],"setup":["step"],'
            . '"tiers":[{"band":"infant|toddler|preschool|school_age","label":"short name for this level","steps":["..."],"stretch":"one way to add challenge"}],'
            . '"safety":["specific safety note"],"domains":["social_emotional|physical|language_literacy|cognitive|creative_arts|self_care|outdoor (1-3)"],'
            . '"watch_for":["what to notice and note, 2-3"],"talk":["open question to ask children, 2-3"],"home_idea":"one easy way families can try this at home"}';
    }

    /**
     * @param array $in bands (band=>count), setting, minutes, involvement, materials[], theme, gaps[], framework name, avoid (titles)
     */
    public static function generate(int $agencyId, array $in): array
    {
        $bands = array_filter($in['bands'] ?? [], fn ($n) => (int) $n > 0);
        if (! $bands) {
            $bands = ['preschool' => 0];
        }
        $lines = [];
        $lines[] = 'Age bands in the room: ' . implode(', ', array_map(fn ($b, $n) => self::BANDS[$b] . ($n ? " x$n" : ''), array_keys($bands), $bands));
        $lines[] = 'Setting: ' . ($in['setting'] ?? 'either') . '. Time: about ' . (int) ($in['minutes'] ?? 20) . ' minutes. Educator involvement: ' . ($in['involvement'] ?? 'guided') . '.';
        if (! empty($in['materials'])) {
            $lines[] = 'Materials on hand: ' . implode(', ', array_slice($in['materials'], 0, 30)) . '.';
        }
        if (! empty($in['theme'])) {
            $lines[] = "This week's theme: " . $in['theme'] . '.';
        }
        if (! empty($in['gaps'])) {
            $lines[] = 'Learning areas this room needs more of lately: ' . implode(', ', $in['gaps']) . '.';
        }
        if (! empty($in['framework'])) {
            $lines[] = 'The program follows: ' . $in['framework'] . '.';
        }
        if (! empty($in['avoid'])) {
            $lines[] = 'Do not repeat these recent ideas: ' . implode('; ', array_slice($in['avoid'], 0, 12)) . '.';
        }
        $user = implode("\n", $lines);
        $under3 = ! empty($bands['infant']) || ! empty($bands['toddler']) || array_key_exists('infant', $bands) || array_key_exists('toddler', $bands);

        UsageMeter::forAgency($agencyId);
        $fix = '';
        $idea = null;
        $check = null;
        for ($try = 0; $try < 2; $try++) {
            AiFresh::as('play_spark', $agencyId);
            $idea = self::call(['max_tokens' => 2200, 'system' => self::systemPrompt(),
                'messages' => [['role' => 'user', 'content' => $user . $fix]]]);
            $idea = self::shape($idea, array_keys($bands));
            $check = self::check($idea, $under3);
            if (! $check['banned'] && ! $check['choking']) {
                break;
            }
            $fix = "\n\nYour last idea used " . implode(', ', array_merge($check['banned'], $check['choking']))
                . '. That is not allowed here. Write a different idea without them.';
        }
        if ($check['banned']) {
            throw new \RuntimeException('Play Spark could not find a safe idea for this. Try different materials.');
        }
        $idea['safety'] = array_values(array_unique(array_merge($idea['safety'], $check['notes'])));
        $idea['safety_level'] = $check['choking'] ? 'caution' : 'ok';

        return $idea;
    }

    /** Keep only the shape we expect, trimmed. */
    private static function shape(array $j, array $bands): array
    {
        $str = fn ($v, $n = 300) => mb_substr(trim((string) (is_scalar($v) ? $v : '')), 0, $n);
        $list = fn ($v, $max = 12, $n = 240) => array_values(array_filter(array_map(fn ($x) => $str($x, $n), is_array($v) ? array_slice($v, 0, $max) : []), 'strlen'));
        $tiers = [];
        foreach (is_array($j['tiers'] ?? null) ? $j['tiers'] : [] as $t) {
            $b = (string) ($t['band'] ?? '');
            if (! isset(self::BANDS[$b])) {
                continue;
            }
            $tiers[] = ['band' => $b, 'band_label' => self::BANDS[$b], 'label' => $str($t['label'] ?? '', 80),
                'steps' => $list($t['steps'] ?? [], 8), 'stretch' => $str($t['stretch'] ?? '', 240)];
        }
        usort($tiers, fn ($a, $b) => array_search($a['band'], array_keys(self::BANDS)) <=> array_search($b['band'], array_keys(self::BANDS)));
        $domains = array_values(array_intersect($list($j['domains'] ?? [], 3, 40), LearningFrameworks::DOMAINS));

        return [
            'title' => $str($j['title'] ?? 'Play idea', 120) ?: 'Play idea',
            'summary' => $str($j['summary'] ?? '', 400),
            'setting' => in_array($j['setting'] ?? '', ['indoor', 'outdoor', 'either'], true) ? $j['setting'] : 'either',
            'minutes' => max(5, min(90, (int) ($j['minutes'] ?? 20))),
            'materials' => $list($j['materials'] ?? [], 20, 80),
            'setup' => $list($j['setup'] ?? [], 8),
            'tiers' => $tiers,
            'safety' => $list($j['safety'] ?? [], 8),
            'domains' => $domains ?: ['cognitive'],
            'watch_for' => $list($j['watch_for'] ?? [], 4),
            'talk' => $list($j['talk'] ?? [], 4),
            'home_idea' => $str($j['home_idea'] ?? '', 400),
        ];
    }

    /** @return array{banned:string[],choking:string[],notes:string[]} */
    public static function check(array $idea, bool $under3): array
    {
        $text = mb_strtolower(implode(' | ', array_merge(
            [$idea['title'] ?? '', $idea['summary'] ?? ''], $idea['materials'] ?? [], $idea['setup'] ?? [],
            ...array_map(fn ($t) => array_merge($t['steps'] ?? [], [$t['stretch'] ?? '']), $idea['tiers'] ?? [])
        )));
        $banned = [];
        foreach (self::BANNED as $re => $label) {
            if (preg_match('/\b(' . $re . ')/u', $text)) {
                $banned[] = $label;
            }
        }
        $choking = [];
        if ($under3) {
            foreach (self::CHOKING as $re => $label) {
                if (preg_match('/\b(' . $re . ')/u', $text)) {
                    $choking[] = $label;
                }
            }
        }
        $notes = ['An educator supervises the whole time.', 'Check the space and every material before you start.'];
        if ($choking) {
            $notes[] = 'Children under 3 are in the room: keep ' . implode(', ', array_unique($choking)) . ' out of their reach and give their level larger items only.';
        }
        if (preg_match('/\b(' . self::FOOD . ')/u', $text)) {
            $notes[] = 'This uses food or play dough: check each child\'s allergies on file first.';
        }

        return ['banned' => array_values(array_unique($banned)), 'choking' => array_values(array_unique($choking)), 'notes' => $notes];
    }

    /** Read a photo of the room's materials. Never stored. Refused if a person is in it. */
    public static function scanMaterials(int $agencyId, string $mime, string $b64): array
    {
        UsageMeter::forAgency($agencyId);
        $j = self::call(['max_tokens' => 600, 'messages' => [['role' => 'user', 'content' => [
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64]],
            ['type' => 'text', 'text' => 'This photo shows play materials in a child care room. If ANY person or part of a person '
                . '(face, hand, body) is visible, return {"people":true,"materials":[]}. Otherwise list up to 20 distinct objects a child '
                . 'could play with, as short plain names (e.g. "cardboard box", "scarves", "wooden blocks"). Return ONLY JSON: '
                . '{"people":false,"materials":["..."]}'],
        ]]]], 45);

        if (! empty($j['people'])) {
            return ['people' => true, 'materials' => []];
        }
        $out = [];
        foreach (is_array($j['materials'] ?? null) ? $j['materials'] : [] as $m) {
            $m = mb_strtolower(mb_substr(trim((string) $m), 0, 60));
            if ($m !== '' && ! in_array($m, $out, true)) {
                $out[] = $m;
            }
        }

        return ['people' => false, 'materials' => array_slice($out, 0, 20)];
    }
}
