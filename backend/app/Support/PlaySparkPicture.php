<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * PLAY SPARK PICTURES (2026-11-24, Anthony: "playspark should also generate an image of the lesson generated as well to
 * show the educator on what the end result will be with the steps provided"). He chose a Claude-drawn illustration
 * (no new image vendor, no new key, nothing extra leaves KiddieTrac) made AUTOMATICALLY with every idea and weekly
 * lesson-plan activity, saved with it so the library, print, the parent view and the emails reuse it.
 *
 * WHAT IS DRAWN. One picture: the FINISHED set-up ("The end result") on top, and 3-4 small numbered panels under it
 * that follow the idea's own steps, each with a short caption. The AI returns only SHAPES for the scene and each panel
 * (JSON); this class lays them out, writes the badges and captions itself (so the words are ours, never the AI's) and
 * sanitises every fragment against a whitelist (shapes, paths, gradients - no text, script, foreignObject, image, use,
 * style, links, event handlers, or url() other than a local gradient). The AI is given the idea's words only: never a
 * child, a name, a photo or a family. Children are drawn as simple generic figures.
 *
 * WHEN. The idea text comes back first, as before; the picture is a queued job (App\Jobs\DrawPlaySparkPicture) so it
 * never slows or blocks the idea. The screen polls ->status() and lazy-fills old ideas. Two tries, then 'failed'
 * (the idea simply shows without one). AI Bundle only, and never for test agencies (AiConfig::offFor): status 'off'.
 *
 * EMAIL. Gmail and Outlook do not show SVG, so the job also saves a PNG (Imagick, in-process - no shell-out) in the
 * private disk under play-spark-pictures/, served by the signed route play-spark.picture.png. The picture holds no
 * personal data; the signature only stops anyone listing them.
 *
 * Rows: play_spark_pictures (agency_id, kind idea|lesson, ref = idea id or the lesson activity's content key).
 */
final class PlaySparkPicture
{
    public const W = 800;
    public const SCENE_H = 440;
    public const STEP_W = 240;
    public const STEP_H = 160;
    private const MAX_PART = 24000;
    private const MAX_NODES = 700;

    private const ELEMENTS = ['g', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'path', 'defs', 'linearGradient', 'radialGradient', 'stop'];
    private const ATTRS = ['x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'width', 'height', 'd', 'points',
        'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'opacity', 'fill-opacity',
        'stroke-opacity', 'fill-rule', 'transform', 'id', 'offset', 'stop-color', 'stop-opacity', 'gradientUnits', 'gradientTransform', 'spreadMethod'];

    /* ── the rows ───────────────────────────────────────────────────────── */

    /** The lesson activity's content key: the same words = the same picture, wherever it is planned. */
    public static function lessonKey(string $title, string $notes): string
    {
        $n = fn ($s) => mb_strtolower(trim(preg_replace('/\s+/', ' ', $s)));

        return 'l' . substr(sha1($n($title) . '|' . $n($notes)), 0, 30);
    }

    public static function find(int $agencyId, string $kind, string $ref): ?object
    {
        return DB::table('play_spark_pictures')->where('agency_id', $agencyId)->where('kind', $kind)->where('ref', $ref)->first();
    }

    /**
     * The picture for the screen: {status: ready|drawing|failed|off, svg?}. Asks for one when there is none yet (a new
     * idea, an idea made before pictures, a lesson activity) and retries a failed one once after 10 minutes.
     * $source = what to draw from (the idea JSON, or {title, notes, theme} for a lesson activity).
     */
    public static function status(int $agencyId, string $kind, string $ref, array $source, bool $withSvg = true, bool $mayQueue = true): array
    {
        if (! AiBundle::on($agencyId) || AiConfig::offFor($agencyId)) {
            $row = self::find($agencyId, $kind, $ref);   // a picture drawn while the bundle was on still shows

            return $row && $row->status === 'ready' ? self::out($row, $withSvg) : ['status' => 'off'];
        }
        $row = self::find($agencyId, $kind, $ref);
        $stale = $row && $row->status === 'drawing' && strtotime((string) $row->updated_at) < time() - 900;   // a job that died
        $retry = $row && $row->status === 'failed' && (int) $row->tries < 2 && strtotime((string) $row->updated_at) < time() - 600;
        if ((! $row || $stale || $retry) && $mayQueue) {
            self::queue($agencyId, $kind, $ref, $source);
            $row = self::find($agencyId, $kind, $ref);
        }

        return self::out($row, $withSvg);
    }

    private static function out(?object $row, bool $withSvg): array
    {
        if (! $row) {
            return ['status' => 'none'];
        }
        $o = ['status' => $row->status];
        if ($row->status === 'ready' && $withSvg) {
            $o['svg'] = $row->svg;
        }

        return $o;
    }

    /** Ask the queue for a picture. Safe to call again: one row per (agency, kind, ref). */
    public static function queue(int $agencyId, string $kind, string $ref, array $source): void
    {
        if (! AiBundle::on($agencyId) || AiConfig::offFor($agencyId)) {
            return;
        }
        $now = now();
        $row = self::find($agencyId, $kind, $ref);
        if ($row) {
            $age = time() - strtotime((string) $row->updated_at);
            if ($row->status === 'ready'
                || ($row->status === 'drawing' && $age < 900)                                  // already on its way
                || ($row->status === 'failed' && ((int) $row->tries >= 2 || $age < 600))) {   // given up, or retried too soon
                return;
            }
        }
        if ($row) {
            DB::table('play_spark_pictures')->where('id', $row->id)->update(['status' => 'drawing', 'source' => json_encode($source), 'updated_at' => $now]);
            $id = (int) $row->id;
        } else {
            try {
                $id = (int) DB::table('play_spark_pictures')->insertGetId(['agency_id' => $agencyId, 'kind' => $kind, 'ref' => $ref,
                    'status' => 'drawing', 'source' => json_encode($source), 'tries' => 0, 'created_at' => $now, 'updated_at' => $now]);
            } catch (\Illuminate\Database\QueryException $e) {
                return;   // a twin request made it a moment ago
            }
        }
        \App\Jobs\DrawPlaySparkPicture::dispatch($id);
    }

    /** The job: draw, sanitise, save the SVG and a PNG for email. */
    public static function run(int $id): void
    {
        $row = DB::table('play_spark_pictures')->find($id);
        if (! $row || $row->status === 'ready') {
            return;
        }
        $agencyId = (int) $row->agency_id;
        if (! AiBundle::on($agencyId) || AiConfig::offFor($agencyId)) {
            DB::table('play_spark_pictures')->where('id', $id)->update(['status' => 'off', 'updated_at' => now()]);

            return;
        }
        DB::table('play_spark_pictures')->where('id', $id)->update(['tries' => DB::raw('tries + 1'), 'updated_at' => now()]);
        $source = json_decode((string) $row->source, true) ?: [];
        try {
            $svg = self::draw($agencyId, $source);
            $png = self::png($svg);
            $path = null;
            if ($png !== null) {
                $path = 'play-spark-pictures/' . $agencyId . '/' . $row->kind . '-' . preg_replace('/[^A-Za-z0-9]/', '', (string) $row->ref) . '.png';
                Storage::disk('local')->put($path, $png);
            }
            DB::table('play_spark_pictures')->where('id', $id)->update(['status' => 'ready', 'svg' => $svg, 'png_path' => $path,
                'error' => null, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::info('Play Spark picture not drawn', ['id' => $id, 'error' => mb_substr($e->getMessage(), 0, 300)]);
            DB::table('play_spark_pictures')->where('id', $id)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250), 'updated_at' => now()]);
        }
    }

    /** A link to the PNG for email (null when there is none). */
    public static function pngUrl(int $agencyId, string $kind, string $ref): ?string
    {
        $row = self::find($agencyId, $kind, $ref);
        if (! $row || $row->status !== 'ready' || ! $row->png_path) {
            return null;
        }
        try {
            return \Illuminate\Support\Facades\URL::signedRoute('play-spark.picture.png', ['id' => (int) $row->id]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * A weekly plan's days with each activity's picture status ('ps_picture': ready | drawing | failed | off | none),
     * in two reads, so the planner draws a "🎨 End result" chip without asking per activity. The SVG itself is
     * fetched only when someone opens it.
     */
    public static function decorateDays(?int $agencyId, array $days): array
    {
        if (! $agencyId) {
            return $days;
        }
        try {
            $keys = [];
            $ideas = [];
            foreach ($days as $acts) {
                foreach ((array) $acts as $a) {
                    if (! is_array($a)) {
                        continue;
                    }
                    if (! empty($a['play_spark_id'])) {
                        $ideas[] = (string) (int) $a['play_spark_id'];
                    } elseif (trim((string) ($a['title'] ?? '')) !== '') {
                        $keys[] = self::lessonKey((string) $a['title'], (string) ($a['notes'] ?? ''));
                    }
                }
            }
            $st = ['lesson' => [], 'idea' => []];
            foreach (['lesson' => $keys, 'idea' => $ideas] as $kind => $refs) {
                if ($refs) {
                    $st[$kind] = DB::table('play_spark_pictures')->where('agency_id', $agencyId)->where('kind', $kind)
                        ->whereIn('ref', array_values(array_unique($refs)))->pluck('status', 'ref')->all();
                }
            }
            foreach ($days as $d => $acts) {
                foreach ((array) $acts as $i => $a) {
                    if (! is_array($a)) {
                        continue;
                    }
                    $days[$d][$i]['ps_picture'] = ! empty($a['play_spark_id'])
                        ? ($st['idea'][(string) (int) $a['play_spark_id']] ?? 'none')
                        : ($st['lesson'][self::lessonKey((string) ($a['title'] ?? ''), (string) ($a['notes'] ?? ''))] ?? 'none');
                }
            }
        } catch (\Throwable $e) {
            // the plan still shows without the chips
        }

        return $days;
    }

    /** The PNG link for a Play Spark idea (the end-of-day emails), from the idea's own agency. Never throws. */
    public static function pngUrlForIdea(int $ideaId): ?string
    {
        try {
            $agencyId = (int) DB::table('play_spark_ideas')->where('id', $ideaId)->value('agency_id');

            return $agencyId ? self::pngUrl($agencyId, 'idea', (string) $ideaId) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /* ── drawing ────────────────────────────────────────────────────────── */

    /** What the AI is told about the activity: its words only. */
    public static function brief(array $s): array
    {
        $str = fn ($v, $n = 200) => mb_substr(trim((string) (is_scalar($v) ? $v : '')), 0, $n);
        $steps = [];
        foreach (array_slice(is_array($s['setup'] ?? null) ? $s['setup'] : [], 0, 3) as $x) {
            $steps[] = $str($x);
        }
        // The level most children in the room are at: the tier with the most steps, else the first.
        $tiers = is_array($s['tiers'] ?? null) ? $s['tiers'] : [];
        $main = null;
        foreach ($tiers as $t) {
            if (! $main || count($t['steps'] ?? []) > count($main['steps'] ?? [])) {
                $main = $t;
            }
        }
        foreach (array_slice(is_array($main['steps'] ?? null) ? $main['steps'] : [], 0, 4) as $x) {
            $steps[] = $str($x);
        }
        $ages = array_values(array_filter(array_map(fn ($t) => PlaySpark::BANDS[$t['band'] ?? ''] ?? null, $tiers)));

        return [
            'title' => $str($s['title'] ?? 'Play activity', 120),
            'summary' => $str($s['summary'] ?? ($s['notes'] ?? ''), 500),
            'setting' => $str($s['setting'] ?? 'either', 10),
            'theme' => $str($s['theme'] ?? '', 120),
            'materials' => array_map(fn ($m) => $str($m, 60), array_slice(is_array($s['materials'] ?? null) ? $s['materials'] : [], 0, 14)),
            'steps' => array_values(array_filter($steps, 'strlen')),
            'ages' => $ages ?: ['Preschool (3 to 5)'],
        ];
    }

    private static function prompt(): string
    {
        return "You are the illustrator for a child care app. Educators see your picture next to a play activity so they know "
            . "what the finished set-up looks like and how to get there. Draw simple, friendly FLAT vector illustrations: soft cheerful "
            . "colours, clean shapes, a light background, no outlines heavier than 3px.\n\n"
            . "Return ONLY JSON:\n"
            . '{"scene":"<svg elements>","steps":[{"caption":"3-6 plain words","svg":"<svg elements>"}]}' . "\n\n"
            . "- scene: the FINISHED activity in a " . self::W . "x" . self::SCENE_H . " canvas (0,0 top-left): the room or outdoors, the set-up with every "
            . "main material clearly visible, and 2-4 young children happily doing the activity (and an educator if it helps). Show it "
            . "from the side or a gentle three-quarter view. Fill the whole canvas (start with a background rect " . self::W . "x" . self::SCENE_H . ").\n"
            . "- steps: 3 or 4 panels, in order, each a " . self::STEP_W . "x" . self::STEP_H . " canvas showing one step being done. Start each with a "
            . "background rect " . self::STEP_W . "x" . self::STEP_H . ". Captions are short imperative phrases (\"Tape the tunnel\", \"Crawl through\").\n"
            . "- People: simple figures (round head, rounded body, varied skin tones and hair, dot eyes and a smile). Children are small; "
            . "the educator is an adult. No logos, no real people.\n"
            . "- Safety: never draw anything unsafe for young children (batteries, magnets, balloons, glass, blades, flames, small loose bits near toddlers).\n"
            . "- Allowed elements ONLY: g, rect, circle, ellipse, line, polyline, polygon, path, defs, linearGradient, radialGradient, stop. "
            . "NO text, image, use, clipPath, mask, filter, pattern, style, foreignObject, script, or CSS. Colours as hex. Gradients by url(#id) only. "
            . "Keep everything inside its canvas. Do not wrap in <svg>.\n"
            . "- Keep it light: scene under 9000 characters, each step under 2500.";
    }

    /** The model that draws. Sonnet, not the app's everyday model (Haiku): side by side on the same idea (2026-11-24) Haiku drew
        floating blocks and faceless figures; Sonnet drew a recognisable room, the set-up and the steps. ~30 s, in the queue.
        ANTHROPIC_PICTURE_MODEL overrides it (env() read directly: config is never cached here). */
    public static ?string $modelOverride = null;

    private static function model(): string
    {
        return self::$modelOverride ?: ((string) env('ANTHROPIC_PICTURE_MODEL') ?: 'claude-sonnet-5-5');
    }

    public static function draw(int $agencyId, array $source): string
    {
        $b = self::brief($source);
        $user = "Activity: " . $b['title'] . "\n" . ($b['summary'] !== '' ? "What it is: " . $b['summary'] . "\n" : '')
            . "Setting: " . $b['setting'] . "\n" . ($b['theme'] !== '' ? "Theme: " . $b['theme'] . "\n" : '')
            . ($b['materials'] ? "Materials: " . implode(', ', $b['materials']) . "\n" : '')
            . "Ages: " . implode(', ', $b['ages']) . "\n"
            . ($b['steps'] ? "Steps:\n- " . implode("\n- ", $b['steps']) . "\n" : '')
            . ($b['steps'] ? "Draw the finished set-up, then 3 or 4 step panels that follow these steps."
                : "Draw the finished set-up, then work out 3 or 4 simple steps from the description and draw a panel for each.");

        UsageMeter::forAgency($agencyId);
        $key = (string) config('services.anthropic.key');
        if ($key === '') {
            throw new \RuntimeException('No AI key.');
        }
        /* Two tries at most: the step panels are the point ("the end result ... with the steps"), and once in a while
           the model sends the scene alone. A second answer without steps is still used - a picture beats none. */
        $scene = '';
        $steps = [];
        for ($try = 0; $try < 2 && count($steps) < 3; $try++) {
            AiFresh::as('play_spark_picture', $agencyId);
            $ask = $user . ($try ? "\n\nYour last answer had no usable step panels. Include \"steps\" with 3 or 4 panels this time." : '');
            $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'])
                ->timeout(110)->post('https://api.anthropic.com/v1/messages', [
                    'model' => self::model(), 'max_tokens' => 9000, 'system' => self::prompt(),
                    'messages' => [['role' => 'user', 'content' => $ask]],
                ]);
            if (! $r->successful()) {
                if ($scene !== '') {
                    break;   // keep the first answer's scene
                }
                throw new \RuntimeException('AI ' . $r->status() . ': ' . mb_substr((string) $r->json('error.message'), 0, 160));
            }
            $text = trim((string) (AiConfig::text($r->json()) ?? ''));
            $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);
            $j = json_decode($text, true);
            if (! is_array($j)) {
                $s0 = strpos($text, '{');
                $e0 = strrpos($text, '}');
                $j = ($s0 !== false && $e0 > $s0) ? json_decode(substr($text, $s0, $e0 - $s0 + 1), true) : null;
            }
            $sc = (is_array($j) && is_string($j['scene'] ?? null)) ? self::clean($j['scene'], 's') : '';
            $st2 = [];
            foreach (array_slice(is_array($j['steps'] ?? null) ? $j['steps'] : [], 0, 4) as $i => $st) {
                $frag = self::clean((string) (is_array($st) ? ($st['svg'] ?? '') : ''), 'p' . $i);
                $cap = mb_substr(trim(preg_replace('/\s+/', ' ', (string) (is_array($st) ? ($st['caption'] ?? '') : ''))), 0, 48);
                if ($frag !== '') {
                    $st2[] = ['svg' => $frag, 'caption' => $cap !== '' ? $cap : ($b['steps'][$i] ?? '')];
                }
            }
            self::$last = ['try' => $try + 1, 'steps_back' => is_array($j['steps'] ?? null) ? count($j['steps']) : 0, 'steps_kept' => count($st2),
                'stop' => (string) $r->json('stop_reason'), 'out_tokens' => (int) $r->json('usage.output_tokens')];
            if (self::$last['steps_back'] !== count($st2) || ! $st2) {
                Log::info('Play Spark picture: step panels missing or dropped', self::$last);
            }
            if ($sc !== '' && ($scene === '' || count($st2) > count($steps))) {
                $scene = $sc;
                $steps = $st2;
            }
        }
        if ($scene === '') {
            throw new \RuntimeException('The picture came back unreadable.');
        }

        return self::compose($b['title'], $scene, $steps);
    }

    /** What the last draw() got back (for the logs and tests). */
    public static array $last = [];

    /**
     * Whitelist sanitiser for one AI fragment. Anything not listed is dropped with its children; every id is
     * prefixed so the scene's and the panels' gradients never collide.
     */
    public static function clean(string $frag, string $prefix): string
    {
        $frag = trim($frag);
        if ($frag === '' || strlen($frag) > self::MAX_PART * 2) {
            return '';
        }
        $frag = preg_replace('/<\?.*?\?>|<!--.*?-->|<!DOCTYPE[^>]*>|<!\[CDATA\[.*?\]\]>/si', '', $frag);
        $frag = preg_replace('#^\s*<svg\b[^>]*>|</svg>\s*$#i', '', $frag);   // told not to wrap; unwrap if it did
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML('<root>' . $frag . '</root>', LIBXML_NONET);   // no entity expansion, no network
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (! $ok || ! $doc->documentElement) {
            return '';
        }
        $count = 0;
        $walk = function (\DOMNode $n) use (&$walk, &$count, $prefix) {
            foreach (iterator_to_array($n->childNodes) as $c) {
                if ($c instanceof \DOMText) {
                    if (trim($c->nodeValue) !== '' || $c instanceof \DOMCdataSection) {
                        $n->removeChild($c);
                    }
                    continue;
                }
                if (! $c instanceof \DOMElement) {
                    $n->removeChild($c);
                    continue;
                }
                $tag = $c->localName;
                if (! in_array($tag, self::ELEMENTS, true) || ++$count > self::MAX_NODES || $c->namespaceURI) {
                    $n->removeChild($c);
                    continue;
                }
                foreach (iterator_to_array($c->attributes) as $a) {
                    $name = $a->nodeName;
                    $v = trim((string) $a->nodeValue);
                    $keep = in_array($name, self::ATTRS, true) && strlen($v) <= (in_array($name, ['d', 'points'], true) ? 6000 : 120)
                        && ! preg_match('/[<>"\'\\\\&]|javascript|expression|@import/i', $v);
                    if ($keep && $name === 'id') {
                        $keep = (bool) preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,40}$/', $v);
                        $v = $prefix . '_' . $v;
                    }
                    if ($keep && stripos($v, 'url(') !== false) {
                        if (in_array($name, ['fill', 'stroke'], true) && preg_match('/^url\(#([A-Za-z][A-Za-z0-9_-]{0,40})\)$/', $v, $m)) {
                            $v = 'url(#' . $prefix . '_' . $m[1] . ')';
                        } else {
                            $keep = false;
                        }
                    }
                    if ($keep && in_array($name, ['fill', 'stroke', 'stop-color'], true) && stripos($v, 'url(') === false
                        && ! preg_match('/^(#[0-9a-f]{3,8}|none|transparent|currentColor|[a-z]{3,20}|rgba?\([\d\s.,%]+\)|hsla?\([\d\s.,%deg]+\))$/i', $v)) {
                        $keep = false;
                    }
                    if ($keep && $name === 'transform' && ! preg_match('/^((translate|scale|rotate|matrix|skewX|skewY)\([-\d\s.,e]+\)\s*)+$/i', $v)) {
                        $keep = false;
                    }
                    if ($keep && in_array($name, ['d', 'points'], true) && ! preg_match('/^[MmLlHhVvCcSsQqTtAaZz\d\s.,eE+-]+$/', $v)) {
                        $keep = false;
                    }
                    if (! $keep) {
                        $c->removeAttribute($name);
                    } elseif ($v !== $a->nodeValue) {
                        $c->setAttribute($name, $v);
                    }
                }
                $walk($c);
            }
        };
        $walk($doc->documentElement);
        $out = '';
        foreach ($doc->documentElement->childNodes as $c) {
            $out .= $doc->saveXML($c);
        }
        $out = trim($out);

        return strlen($out) > self::MAX_PART ? '' : $out;
    }

    /** Lay out: the end result on top, numbered step panels under it, our own captions. */
    public static function compose(string $title, string $scene, array $steps): string
    {
        $W = self::W;
        $n = count($steps);
        $gap = 16;
        $pw = $n ? ($W - ($n - 1) * $gap) / $n : 0;
        $scale = $n ? $pw / self::STEP_W : 0;
        $ph = self::STEP_H * $scale;
        $top = self::SCENE_H + 18;
        $capH = 44;
        $H = (int) ceil($n ? $top + $ph + $capH : self::SCENE_H);
        $e = fn ($s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $font = 'font-family="Inter, Arial, Helvetica, sans-serif"';

        /* Groups with a transform, not nested <svg>: Imagick's own SVG renderer (the email PNG) draws nested <svg>
           wrongly. Overflow is tidied by painting white over the gutters after the parts. */
        $s = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $W . ' ' . $H . '" width="' . $W . '" height="' . $H . '" role="img">'
            . '<title>' . $e($title . ': the end result' . ($n ? ' and ' . $n . ' steps' : '')) . '</title>'
            . '<g stroke="none">'   // Imagick's renderer outlines shapes that name no stroke; browsers do not
            . '<rect x="0" y="0" width="' . $W . '" height="' . $H . '" fill="#FFFFFF"/>'
            . '<g>' . $scene . '</g>';
        $colors = ['#FF5A4E', '#E8A400', '#3D8BFF', '#22B37C'];
        $xs = [];
        foreach ($steps as $i => $st) {
            $x = round($i * ($pw + $gap), 2);
            $xs[] = $x;
        }
        $white = fn ($x, $y, $w, $h) => '<rect x="' . round($x, 2) . '" y="' . round($y, 2) . '" width="' . round($w, 2) . '" height="' . round($h, 2) . '" fill="#FFFFFF"/>';
        $s .= $white(0, self::SCENE_H, $W, $H - self::SCENE_H + 400);   // under the scene (anything the scene drew too low)
        foreach ($steps as $i => $st) {   // the panels, on that white; the gutters below trim anything drawn outside a panel
            $s .= '<g transform="translate(' . $xs[$i] . ' ' . $top . ') scale(' . round($scale, 5) . ')">' . $st['svg'] . '</g>';
        }
        $s .= $white(0, self::SCENE_H, $W, $top - self::SCENE_H) . $white(0, $top + $ph, $W, $H - $top - $ph + 400)
            . $white(-400, $top, 400, $ph) . $white($W, $top, 400, $ph);
        foreach ($xs as $i => $x) {
            if ($i > 0) {
                $s .= $white($x - $gap, $top, $gap, $ph);
            }
        }
        $s .= '<rect x="14" y="14" width="168" height="30" rx="15" fill="#0B1026" fill-opacity="0.86"/>'
            . '<circle cx="31" cy="29" r="7" fill="#FFC531"/>'
            . '<text x="46" y="34" ' . $font . ' font-size="14" font-weight="700" fill="#FFFFFF">The end result</text>';
        foreach ($steps as $i => $st) {
            $x = $xs[$i];
            $s .= '<rect x="' . $x . '" y="' . $top . '" width="' . round($pw, 2) . '" height="' . round($ph, 2) . '" rx="10" fill="none" stroke="#D7DDE8" stroke-width="1.5"/>'
                . '<circle cx="' . ($x + 16) . '" cy="' . ($top + 16) . '" r="12" fill="' . $colors[$i % 4] . '"/>'
                . '<text x="' . ($x + 16) . '" y="' . ($top + 21) . '" ' . $font . ' font-size="14" font-weight="800" fill="#FFFFFF" text-anchor="middle">' . ($i + 1) . '</text>';
            $lines = self::wrap((string) $st['caption'], max(12, (int) floor($pw / 8.2)));
            foreach (array_slice($lines, 0, 2) as $li => $line) {
                $s .= '<text x="' . ($x + 2) . '" y="' . round($top + $ph + 18 + $li * 17, 1) . '" ' . $font . ' font-size="14" font-weight="' . ($li ? '600' : '700') . '" fill="#1E293B">' . $e($line) . '</text>';
            }
        }

        return $s . '</g></svg>';
    }

    private static function wrap(string $t, int $max): array
    {
        $lines = [];
        $cur = '';
        foreach (preg_split('/\s+/', trim($t)) as $w) {
            if ($cur !== '' && mb_strlen($cur . ' ' . $w) > $max) {
                $lines[] = $cur;
                $cur = $w;
            } else {
                $cur = $cur === '' ? $w : $cur . ' ' . $w;
            }
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }
        if (count($lines) > 2) {
            $lines = [$lines[0], mb_substr(implode(' ', array_slice($lines, 1)), 0, $max - 1) . '…'];
        }

        return $lines;
    }

    /** PNG for email, in-process (web and queue PHP have Imagick; no shell-out). Null when it cannot. */
    public static function png(string $svg): ?string
    {
        if (! class_exists(\Imagick::class)) {
            return null;
        }
        try {
            $im = new \Imagick();
            $im->setBackgroundColor(new \ImagickPixel('#FFFFFF'));
            $im->setResolution(144, 144);
            $im->readImageBlob('<?xml version="1.0" encoding="UTF-8"?>' . $svg);
            $im->setImageFormat('png');
            $im->thumbnailImage(1200, 0);
            $im->stripImage();
            $blob = $im->getImageBlob();
            $im->clear();

            return strlen($blob) > 2000 ? $blob : null;
        } catch (\Throwable $e) {
            Log::info('Play Spark picture PNG failed', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return null;
        }
    }
}
