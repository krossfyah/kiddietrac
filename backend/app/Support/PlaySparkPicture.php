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
 * VERSION 2 (2026-11-27, Anthony: "the playspark images that are drawn needs to be better and more precise so the
 * educators can follow this"). Version 1 asked one call for the scene AND four tiny panels at once: the panels came out
 * 186 px wide with whole figures in them, half of them spent on prep ("Clear the path"), captions capped at six words,
 * no hands, objects nobody could name. Version 2 is a small pipeline, all in the queue:
 *   1. PLAN   - one call turns the idea into a storyboard: the materials with fixed colours and looks (so the same tube
 *               is the same tube in every panel), the end-result scene, and exactly 4 steps that TEACH the activity
 *               (never safety checks or "gather the children"), each with a full instruction caption, a close-up
 *               description, the hands, the movement and which materials get a name tag.
 *   2. DRAW   - five calls at once (Http::pool): the scene and each step panel, each with the whole plan for
 *               consistency. The AI returns SHAPES plus a few structured extras that WE draw: hands from our own
 *               library (grip, open, point, pinch, with a sleeve), movement arrows, name tags for materials and
 *               single letters/numbers printed on objects (foam letters, number blocks).
 *   3. CHECK  - the composed picture is rendered to PNG (resvg, see png()) and one vision call checks every part
 *               against the plan. A part an educator could not follow is drawn again ONCE with the problem named.
 * The layout is ours: the scene on top, then the steps two by two at 392 px with captions of up to three lines.
 *
 * SAFETY (unchanged). The AI is given the idea's words only: never a child, a name, a photo or a family. Every SVG
 * fragment goes through clean() (a whitelist: shapes, paths, gradients - no text, script, foreignObject, image, use,
 * style, links, handlers, or url() other than a local gradient). Words in the picture are ours: the captions come
 * from the plan, and tags/marks are short plain strings we escape and lay out ourselves.
 *
 * WHEN. The idea text comes back first, as before; the picture is a queued job (App\Jobs\DrawPlaySparkPicture, its
 * own "pictures" worker) so it never slows the idea or the email queue. The screen polls ->status() and lazy-fills
 * old ideas. Two tries, then 'failed' (the idea simply shows without one). AI Bundle only, never for test agencies
 * (AiConfig::offFor): status 'off'. Staff can ask for a fresh drawing (redraw()); the old picture stays up until
 * the new one is ready.
 *
 * EMAIL. Gmail and Outlook do not show SVG, so the job also saves a PNG in the private disk under
 * play-spark-pictures/, served by the signed route play-spark.picture.png.
 *
 * Rows: play_spark_pictures (agency_id, kind idea|lesson, ref = idea id or the lesson activity's content key,
 * version 1|2, redraw_at, meta = {steps:[captions], model, ms, cost, fixed}).
 */
final class PlaySparkPicture
{
    public const VERSION = 2;
    public const W = 800;
    public const SCENE_H = 440;
    public const STEP_W = 360;
    public const STEP_H = 240;
    private const MAX_PART = 30000;
    private const MAX_NODES = 900;

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
     * The picture for the screen: {status: ready|drawing|failed|off, svg?, steps?, redrawing?}. Asks for one when there
     * is none yet (a new idea, an idea made before pictures, a lesson activity) and retries a failed one once after
     * 10 minutes. $source = what to draw from (the idea JSON, or {title, notes, theme} for a lesson activity).
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
            $meta = json_decode((string) ($row->meta ?? ''), true);
            if (is_array($meta['steps'] ?? null)) {
                $o['steps'] = array_values(array_map('strval', $meta['steps']));   // the captions as text (phones, screen readers)
            }
        }
        if ($row->status === 'ready' && ! empty($row->redraw_at) && strtotime((string) $row->redraw_at) > time() - 900) {
            $o['redrawing'] = true;
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

    /**
     * Draw a ready picture again (staff "↻ Redraw picture", the v1 → v2 backfill). The old picture keeps showing until
     * the new one is saved; if the new drawing fails the old one simply stays. Returns false when one is already on
     * its way (or was asked for in the last 10 minutes), when AI is off, or when there is nothing to draw from.
     */
    public static function redraw(int $agencyId, string $kind, string $ref, ?array $source = null): bool
    {
        if (! AiBundle::on($agencyId) || AiConfig::offFor($agencyId)) {
            return false;
        }
        $row = self::find($agencyId, $kind, $ref);
        if (! $row) {
            if ($source === null) {
                return false;
            }
            self::queue($agencyId, $kind, $ref, $source);

            return true;
        }
        if ($row->status !== 'ready') {
            if ($row->status === 'failed') {   // a staff member asking again: one more go now
                DB::table('play_spark_pictures')->where('id', $row->id)->update(['tries' => 0, 'updated_at' => now()->subMinutes(11)]);
                self::queue($agencyId, $kind, $ref, $source ?? (json_decode((string) $row->source, true) ?: []));

                return true;
            }

            return false;   // drawing already
        }
        if (! empty($row->redraw_at) && strtotime((string) $row->redraw_at) > time() - 600) {
            return false;
        }
        $upd = ['redraw_at' => now(), 'updated_at' => now()];
        if ($source) {
            $upd['source'] = json_encode($source);
        }
        DB::table('play_spark_pictures')->where('id', $row->id)->update($upd);
        \App\Jobs\DrawPlaySparkPicture::dispatch((int) $row->id);

        return true;
    }

    /** The job: draw, sanitise, save the SVG and a PNG for email. */
    public static function run(int $id): void
    {
        $row = DB::table('play_spark_pictures')->find($id);
        if (! $row) {
            return;
        }
        $redraw = $row->status === 'ready';
        if ($redraw && empty($row->redraw_at)) {
            return;
        }
        $agencyId = (int) $row->agency_id;
        if (! AiBundle::on($agencyId) || AiConfig::offFor($agencyId)) {
            DB::table('play_spark_pictures')->where('id', $id)->update($redraw ? ['redraw_at' => null] : ['status' => 'off', 'updated_at' => now()]);

            return;
        }
        if (! $redraw) {
            DB::table('play_spark_pictures')->where('id', $id)->update(['tries' => DB::raw('tries + 1'), 'updated_at' => now()]);
        }
        $source = json_decode((string) $row->source, true) ?: [];
        try {
            $svg = self::draw($agencyId, $source);
            $png = self::png($svg);
            $path = $row->png_path ?: null;
            if ($png !== null) {
                $path = 'play-spark-pictures/' . $agencyId . '/' . $row->kind . '-' . preg_replace('/[^A-Za-z0-9]/', '', (string) $row->ref) . '.png';
                Storage::disk('local')->put($path, $png);
            }
            DB::table('play_spark_pictures')->where('id', $id)->update(['status' => 'ready', 'svg' => $svg, 'png_path' => $path,
                'version' => self::VERSION, 'meta' => json_encode(self::$last['meta'] ?? null), 'redraw_at' => null,
                'error' => null, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::info('Play Spark picture not drawn', ['id' => $id, 'redraw' => $redraw, 'error' => mb_substr($e->getMessage(), 0, 300)]);
            DB::table('play_spark_pictures')->where('id', $id)->update($redraw
                ? ['redraw_at' => null, 'error' => mb_substr($e->getMessage(), 0, 250)]   // the old picture stays
                : ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250), 'updated_at' => now()]);
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

    /* ── what the AI is told ───────────────────────────────────────────── */

    /** What the AI is told about the activity: its words only. */
    public static function brief(array $s): array
    {
        $str = fn ($v, $n = 240) => mb_substr(trim((string) (is_scalar($v) ? $v : '')), 0, $n);
        $setup = [];
        foreach (array_slice(is_array($s['setup'] ?? null) ? $s['setup'] : [], 0, 5) as $x) {
            $setup[] = $str($x);
        }
        /* The level most children are at: the tier with the most steps; on a tie the middle bands win over infants
           (v1 took the first tier, so a mixed room's picture showed the infant steps). */
        $tiers = is_array($s['tiers'] ?? null) ? $s['tiers'] : [];
        $rank = ['preschool' => 4, 'toddler' => 3, 'school_age' => 2, 'infant' => 1];
        $main = null;
        foreach ($tiers as $t) {
            if (! is_array($t)) {
                continue;
            }
            $score = count(is_array($t['steps'] ?? null) ? $t['steps'] : []) * 10 + ($rank[$t['band'] ?? ''] ?? 0);
            if (! $main || $score > $main[0]) {
                $main = [$score, $t];
            }
        }
        $main = $main[1] ?? null;
        $steps = [];
        foreach (array_slice(is_array($main['steps'] ?? null) ? $main['steps'] : [], 0, 6) as $x) {
            $steps[] = $str($x);
        }
        $ages = array_values(array_filter(array_map(fn ($t) => PlaySpark::BANDS[$t['band'] ?? ''] ?? null, array_filter($tiers, 'is_array'))));

        return [
            'title' => $str($s['title'] ?? 'Play activity', 120),
            'summary' => $str($s['summary'] ?? ($s['notes'] ?? ''), 600),
            'setting' => $str($s['setting'] ?? 'either', 10),
            'theme' => $str($s['theme'] ?? '', 120),
            'materials' => array_map(fn ($m) => $str($m, 70), array_slice(is_array($s['materials'] ?? null) ? $s['materials'] : [], 0, 16)),
            'setup' => array_values(array_filter($setup, 'strlen')),
            'steps' => array_values(array_filter($steps, 'strlen')),
            'main_age' => $main ? (PlaySpark::BANDS[$main['band'] ?? ''] ?? '') : '',
            'ages' => $ages ?: ['Preschool (3 to 5)'],
        ];
    }

    private static function activityText(array $b): string
    {
        return "Activity: " . $b['title'] . "\n"
            . ($b['summary'] !== '' ? "What it is: " . $b['summary'] . "\n" : '')
            . "Setting: " . $b['setting'] . "\n" . ($b['theme'] !== '' ? "Theme: " . $b['theme'] . "\n" : '')
            . "Ages: " . implode(', ', $b['ages']) . ($b['main_age'] !== '' ? " (the steps below are for " . $b['main_age'] . ")" : '') . "\n"
            . ($b['materials'] ? "Materials: " . implode('; ', $b['materials']) . "\n" : '')
            . ($b['setup'] ? "Set-up:\n- " . implode("\n- ", $b['setup']) . "\n" : '')
            . ($b['steps'] ? "Steps with the children:\n- " . implode("\n- ", $b['steps']) . "\n" : '');
    }

    private static function planPrompt(): string
    {
        return "You plan a picture that teaches an early-childhood educator how to run one play activity. The picture has a large "
            . "\"end result\" scene (the activity set up and happening) and exactly 4 numbered step panels the educator follows in order. "
            . "An illustrator draws from your plan with simple flat shapes, so describe only things that can be drawn plainly. "
            . "Children are simple friendly figures; never name or describe a real child.\n\n"
            . "Return ONLY JSON:\n"
            . '{"objects":[{"key":"tube","name":"paper towel tube","look":"tan cardboard tube, four times taller than wide, dark oval opening on top","colors":["#C9965B","#8A5A2B"]}],'
            . '"scene":{"show":"...","labels":["tube"]},'
            . '"steps":[{"caption":"...","show":"...","hands":"...","motion":"...","labels":["tube"]}]}' . "\n\n"
            . "Rules\n"
            . "- objects: the 3 to 8 materials that matter, each with ONE look (shape, proportions, the parts that make it recognisable, max 25 words) "
            . "and fixed hex colours. The illustrator uses exactly these in every part, so the same tube is the same tube everywhere. "
            . "Pick colours the activity names; otherwise clear, distinct colours.\n"
            . "- scene.show (max 70 words): the finished activity seen from the front at child height: where each material sits, what 2 or 3 "
            . "children and one educator are doing with them. It must look like the activity's title.\n"
            . "- steps: exactly 4, in the order the educator does them, chosen to TEACH the activity. Usually step 1 is the one set-up action "
            . "that builds or lays out the materials; steps 2-4 are what the children and educator actually do, ending close to the end result. "
            . "Never spend a step on checking safety, clearing space, gathering or seating children, handing out, praising, talking only, "
            . "watching, or tidying up.\n"
            . "- caption: the instruction the educator follows, 7 to 16 words, starting with a verb, naming the material with its colour and "
            . "count, e.g. \"Drop one foam letter into the top of the upright tube.\" A sentence, not a title.\n"
            . "- show (max 50 words): a CLOSE-UP of that one action: which objects and how many, where they sit, what changes (before -> after). "
            . "Large objects, little background, no full figures unless the step is about moving the whole body.\n"
            . "- hands: whose hands are in the panel and what each does (\"adult hand grips the tube upright; child's hand pinches a letter above it\"), or \"none\".\n"
            . "- motion: what moves and where (\"letter drops down into the tube\"), or \"none\".\n"
            . "- counts: small and exact (1 to 6), the same from panel to panel unless a step changes them.\n"
            . "- labels: object keys that need a small name tag because a simple drawing of them is not obvious (\"sorting mat\", \"hair gel\", "
            . "\"masking tape\", \"foam roller\"); never everyday things anyone recognises (an apple, a ball, a cup, a leaf, a child). Tag each object "
            . "at most once across the 4 steps, in the first step it matters. Up to 2 per step and 2 for the scene; [] is fine.\n"
            . "- Only the activity's own materials. Nothing unsafe for young children: no balloons, batteries, magnets, glass, blades, flames, "
            . "or small loose pieces near children under 3; scissors only ever in an adult's hand.";
    }

    private static function drawPrompt(): string
    {
        $W = self::W;
        $H = self::SCENE_H;
        $sw = self::STEP_W;
        $sh = self::STEP_H;

        return "You illustrate instruction pictures for a child care app. Educators follow your drawings to run a play activity, so every "
            . "object must be recognisable at a glance and every step must show exactly the action in its caption.\n\n"
            . "Return ONLY JSON:\n"
            . '{"svg":"<svg elements>","hands":[],"arrows":[],"labels":[],"marks":[]}' . "\n\n"
            . "DRAWING\n"
            . "- Flat vector style, soft cheerful colours, a light plain background. Give every object a clear silhouette with a darker "
            . "outline (stroke 2 to 3) and one lighter highlight or darker shade so it reads as solid.\n"
            . "- Use the plan's objects exactly: the same look, colours and counts.\n"
            . "- Make recognisable objects with the parts that identify them: a cup has a rim ellipse and a tapered body; a bowl a wide "
            . "rim ellipse over a half-ellipse; a crayon a wrapped body and a cone tip; a paint brush a handle, a metal band and a bristle tip; "
            . "paper is a flat sheet with a soft shadow; a block a cube in three-quarter view with a lighter top face; a tube a cylinder with an "
            . "oval opening; a tray a shallow box with a visible rim; a leaf a pointed oval with a centre vein; a ball a circle with a curved "
            . "stripe; a basket woven lines; water a pale blue shape with a lighter ripple; a scarf a soft wavy cloth.\n"
            . "- People (scene, and any panel that needs a whole person): round head with two dot eyes and a small smile curve, a simple "
            . "hair shape, a rounded body, legs; arms are thick round-capped strokes from the shoulder to where a hand from \"hands\" sits. "
            . "Children are small, the educator an adult. Varied skin tones. Every person has a whole body: head, body, legs and feet resting ON "
            . "the floor or ground (sitting, kneeling or standing), never floating or cut off. When a step is done with the feet (stomping, "
            . "walking, kicking), draw the legs and feet doing it, not hands.\n"
            . "- Nothing floats unless it is falling; show falling with an arrow. Objects rest on a table, tray, floor or ground with a soft shadow.\n"
            . "- Allowed elements ONLY: g, rect, circle, ellipse, line, polyline, polygon, path, defs, linearGradient, radialGradient, stop. "
            . "NO text, image, use, clipPath, mask, filter, pattern, style, foreignObject, script or CSS. Colours as hex. Gradients by url(#id) only. "
            . "Do not wrap in <svg>. Keep everything inside the canvas.\n\n"
            . "EXTRAS (we draw these on top of your shapes, so leave room for them)\n"
            . "- hands (STEP PANELS ONLY; in the scene give people small round hands on their own arms and leave this empty): "
            . "[{\"x\":..,\"y\":..,\"angle\":..,\"pose\":\"grip|open|point|pinch\",\"skin\":\"#hex\",\"sleeve\":\"#hex\",\"reach\":..,\"size\":..}]. "
            . "(x,y) is the centre of the palm. angle is the direction the fingers point in degrees (0 right, 90 down, 180 left, 270 up). "
            . "We run the forearm and sleeve from behind the palm straight to the panel edge, so point the fingers AWAY from the edge the "
            . "person is at (an adult reaching in from the top: angle 90). Only when you drew the whole person in the panel yourself, set "
            . "\"reach\":0 and put the palm at the end of their arm. "
            . "At size 1 a hand is about 60 px from wrist to fingertip and 34 px wide (size 0.4 to 2). grip = a fist closed around something: "
            . "draw the held object passing under the palm centre. open = a flat hand (pressing, patting, holding out). point = index finger out. "
            . "pinch = thumb and finger tips together holding something small about 30 px ahead of the palm centre. Use hands for every action a "
            . "hand does: that is how the educator sees what to do.\n"
            . "- arrows: [{\"x1\":..,\"y1\":..,\"x2\":..,\"y2\":..,\"bend\":-1..1}] bold arrows for movement (dropping, rolling, pouring, stacking, "
            . "before -> after). 0 to 2 per part, starting and ending next to the object, not on it.\n"
            . "- labels: [{\"text\":\"1-3 words\",\"x\":..,\"y\":..}] (x,y) = the middle of the object the name tag points to; we place the tag "
            . "itself in clear space nearby. Only the tags this part asks for.\n"
            . "- marks: [{\"text\":\"A\",\"x\":..,\"y\":..,\"size\":..,\"color\":\"#hex\"}] up to 8 single letters or numbers printed ON an object "
            . "(the letter on a foam letter, the number on a block or card), centred on (x,y).\n\n"
            . "CANVASES\n"
            . "- The scene is {$W}x{$H} (0,0 top-left). Start with a background rect {$W}x{$H}. Put the floor or ground line about a third "
            . "of the way down, so the activity, the materials and the people fill the lower two thirds at a good size. Leave the top-left "
            . "200x56 corner plain: our \"The end result\" badge sits there.\n"
            . "- A step panel is {$sw}x{$sh}. Start with a background rect {$sw}x{$sh}. Show ONE action as a close-up: the objects and hands fill "
            . "most of the panel. Leave the top-left 40x40 corner plain: the step number sits there.\n"
            . "- Keep it efficient: the scene under 12000 characters of svg, a panel under 6000.";
    }

    /* ── drawing ────────────────────────────────────────────────────────── */

    /** The models. DRAW = Opus 5.5 (low effort): side by side on the same six ideas (2026-11-27) Sonnet 5.5 drew the
        step panels nearly as well but its scenes had arms through tables and floating fists; Opus drew whole people
        standing on the floor doing the activity. ~$0.55 a picture against Sonnet's ~$0.33. PLAN and CHECK = Sonnet
        (words, and one look at the picture). ANTHROPIC_PICTURE_MODEL overrides the drawing model,
        ANTHROPIC_PICTURE_PLAN_MODEL the other two (env() is read directly: config is never cached here). */
    public static ?string $modelOverride = null;
    public static ?string $planModelOverride = null;
    /** Tests: skip the render-and-check pass. */
    public static bool $skipCheck = false;

    private static function model(): string
    {
        return self::$modelOverride ?: ((string) env('ANTHROPIC_PICTURE_MODEL') ?: 'claude-opus-5-5');
    }

    private static function planModel(): string
    {
        return self::$planModelOverride ?: ((string) env('ANTHROPIC_PICTURE_PLAN_MODEL') ?: 'claude-sonnet-5-5');
    }

    /** What the last draw() did (for the logs, tests and the row's meta). */
    public static array $last = [];

    /** Token totals for this draw, per model (for meta + cost). */
    private static array $usage = [];

    private static function body(string $model, string $system, array $content, int $maxTokens): array
    {
        $b = ['model' => $model, 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $content]]];
        if (str_starts_with($model, 'claude-opus-5')) {
            $b['thinking'] = ['type' => 'adaptive'];       // cannot be switched off on Opus 5.5; low effort keeps it short
            $b['output_config'] = ['effort' => 'low'];
        }

        return $b;   // Sonnet: AiConfig's request middleware sends thinking "between_tools" (no thinking)
    }

    private static function headers(): array
    {
        $key = (string) config('services.anthropic.key');
        if ($key === '') {
            throw new \RuntimeException('No AI key.');
        }

        return ['x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'];
    }

    /** One reply's JSON object (fences and chatter around it tolerated), or null. Counts the tokens. */
    private static function replyJson($r, string $model): ?array
    {
        if (! $r instanceof \Illuminate\Http\Client\Response || ! $r->successful()) {
            return null;
        }
        $u = (array) $r->json('usage');
        self::$usage[$model]['in'] = (self::$usage[$model]['in'] ?? 0) + (int) ($u['input_tokens'] ?? 0) + (int) ($u['cache_read_input_tokens'] ?? 0) + (int) ($u['cache_creation_input_tokens'] ?? 0);
        self::$usage[$model]['out'] = (self::$usage[$model]['out'] ?? 0) + (int) ($u['output_tokens'] ?? 0);
        if ($r->json('stop_reason') === 'refusal') {
            return null;
        }
        $text = trim((string) (AiConfig::text($r->json()) ?? ''));
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);
        $j = json_decode($text, true);
        if (! is_array($j)) {
            $s0 = strpos($text, '{');
            $e0 = strrpos($text, '}');
            $j = ($s0 !== false && $e0 > $s0) ? json_decode(substr($text, $s0, $e0 - $s0 + 1), true) : null;
        }

        return is_array($j) ? $j : null;
    }

    public static function draw(int $agencyId, array $source): string
    {
        $t0 = microtime(true);
        self::$usage = [];
        self::$last = [];
        $b = self::brief($source);
        UsageMeter::forAgency($agencyId);
        $headers = self::headers();

        // 1. PLAN (the words people read: AiFresh keeps its phrasing from repeating across an agency's pictures)
        $plan = null;
        for ($try = 0; $try < 2 && ! $plan; $try++) {
            AiFresh::as('play_spark_picture', $agencyId);
            $r = Http::withHeaders($headers)->timeout(120)->post('https://api.anthropic.com/v1/messages',
                self::body(self::planModel(), self::planPrompt(), [['type' => 'text', 'text' => self::activityText($b)]], 4000));
            if (! $r->successful() && $try) {
                throw new \RuntimeException('AI ' . $r->status() . ': ' . mb_substr((string) $r->json('error.message'), 0, 160));
            }
            $plan = self::cleanPlan(self::replyJson($r, self::planModel()));
        }
        if (! $plan) {
            throw new \RuntimeException('The picture plan came back unreadable.');
        }
        $t1 = microtime(true);

        // 2. DRAW the scene and the panels at once
        $parts = self::drawParts($b, $plan, array_merge(['scene'], array_keys($plan['steps'])), []);
        if (! isset($parts['scene'])) {
            throw new \RuntimeException('The picture came back unreadable.');
        }
        $t2 = microtime(true);
        $svg = self::compose($b['title'], $plan, $parts);

        // 3. CHECK the rendered picture; draw a failing part again once
        $fixed = [];
        if (! self::$skipCheck) {
            $png = self::png($svg, 1000);
            $bad = $png ? self::check($plan, $png, array_keys($parts)) : [];
            self::$last['first_svg'] = $bad ? $svg : null;   // tests compare before / after the fix
            if ($bad) {
                $again = self::drawParts($b, $plan, array_keys($bad), $bad);
                foreach ($again as $k => $p) {
                    $parts[$k] = $p;
                    $fixed[] = (string) $k;
                }
                if ($again) {
                    $svg = self::compose($b['title'], $plan, $parts);
                }
            }
            self::$last['problems'] = $bad;
        }
        $cost = 0.0;
        foreach (self::$usage as $m => $u) {
            $cost += AiPricing::cost($m, (int) ($u['in'] ?? 0), (int) ($u['out'] ?? 0));
        }
        self::$last['meta'] = [
            'v' => self::VERSION,
            'steps' => array_values(array_map(fn ($s) => $s['caption'], array_intersect_key($plan['steps'], $parts))),
            'model' => self::model(), 'plan_model' => self::planModel(),
            'ms' => (int) round((microtime(true) - $t0) * 1000), 'ms_plan' => (int) round(($t1 - $t0) * 1000), 'ms_draw' => (int) round(($t2 - $t1) * 1000),
            'usage' => self::$usage, 'cost' => round($cost, 4), 'fixed' => $fixed,
        ];
        self::$last['plan'] = $plan;

        return $svg;
    }

    /** The plan, trimmed to what we use; null when it has no scene or fewer than 3 steps. */
    private static function cleanPlan(?array $j): ?array
    {
        if (! $j) {
            return null;
        }
        $s = fn ($v, $n) => mb_substr(trim(preg_replace('/\s+/', ' ', (string) (is_scalar($v) ? $v : ''))), 0, $n);
        $objects = [];
        foreach (array_slice(is_array($j['objects'] ?? null) ? $j['objects'] : [], 0, 8) as $o) {
            if (! is_array($o) || $s($o['name'] ?? '', 40) === '') {
                continue;
            }
            $key = preg_replace('/[^a-z0-9_-]/', '', strtolower($s($o['key'] ?? $o['name'], 24))) ?: 'o' . count($objects);
            $colors = array_values(array_filter(array_map(fn ($c) => self::hex($c), array_slice(is_array($o['colors'] ?? null) ? $o['colors'] : [], 0, 3))));
            $objects[$key] = ['name' => $s($o['name'], 40), 'look' => $s($o['look'] ?? '', 200), 'colors' => $colors];
        }
        $labels = fn ($l, $n) => array_values(array_filter(array_map(fn ($k) => isset($objects[$k = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $k))]) ? $k : null,
            array_slice(is_array($l) ? $l : [], 0, $n))));
        $scene = is_array($j['scene'] ?? null) ? $j['scene'] : [];
        $steps = [];
        $tagged = [];   // each object gets one name tag across the steps
        foreach (array_slice(is_array($j['steps'] ?? null) ? $j['steps'] : [], 0, 4) as $st) {
            if (! is_array($st) || $s($st['caption'] ?? '', 160) === '') {
                continue;
            }
            $cap = $s($st['caption'], 130);
            $steps[] = ['caption' => preg_match('/[.!?]$/', $cap) ? $cap : $cap . '.', 'show' => $s($st['show'] ?? '', 400),
                'hands' => $s($st['hands'] ?? '', 200), 'motion' => $s($st['motion'] ?? '', 160),
                'labels' => array_values(array_diff($labels($st['labels'] ?? [], 2), $tagged))];
            $tagged = array_merge($tagged, $steps[count($steps) - 1]['labels']);
        }
        if (count($steps) < 3 || $s($scene['show'] ?? '', 10) === '') {
            return null;
        }

        return ['objects' => $objects, 'scene' => ['show' => $s($scene['show'], 600), 'labels' => $labels($scene['labels'] ?? [], 2)], 'steps' => $steps];
    }

    /**
     * Draw the named parts ('scene', 0..3) in parallel. $fix = part => the problem the check found (redraws).
     * Returns part => {svg, hands, arrows, labels, marks}; a part that comes back unusable is left out.
     */
    private static function drawParts(array $b, array $plan, array $which, array $fix): array
    {
        $model = self::model();
        $headers = self::headers();
        $objects = [];
        foreach ($plan['objects'] as $k => $o) {
            $objects[] = "- {$k}: {$o['name']} - {$o['look']}" . ($o['colors'] ? ' (colours ' . implode(', ', $o['colors']) . ')' : '');
        }
        $common = "Activity: {$b['title']} (" . ($b['main_age'] ?: implode(', ', $b['ages'])) . ", {$b['setting']})\n"
            . "Objects - draw them exactly like this everywhere:\n" . implode("\n", $objects) . "\n"
            . "The end result: {$plan['scene']['show']}\n"
            . "The steps:\n" . implode("\n", array_map(fn ($i, $st) => ($i + 1) . ". {$st['caption']}", array_keys($plan['steps']), $plan['steps'])) . "\n\n";
        $asks = [];
        foreach ($which as $part) {
            if ($part === 'scene') {
                $ask = "Draw THE END RESULT scene ({$b['setting']}).\nShow: {$plan['scene']['show']}\n"
                    . 'Name tags for: ' . ($plan['scene']['labels'] ? implode(', ', array_map(fn ($k) => $plan['objects'][$k]['name'], $plan['scene']['labels'])) : 'none') . '.';
            } else {
                $st = $plan['steps'][$part] ?? null;
                if (! $st) {
                    continue;
                }
                $ask = 'Draw STEP ' . ($part + 1) . " as a close-up panel.\nCaption (we print it under the panel): {$st['caption']}\nShow: {$st['show']}\n"
                    . "Hands: {$st['hands']}\nMovement: {$st['motion']}\n"
                    . 'Name tags for: ' . ($st['labels'] ? implode(', ', array_map(fn ($k) => $plan['objects'][$k]['name'], $st['labels'])) : 'none') . '.';
            }
            if (isset($fix[$part]) && $fix[$part] !== '') {
                $ask .= "\n\nA reviewer rejected the last drawing of this part: " . $fix[$part] . "\nDraw it again and fix exactly that.";
            }
            $asks[(string) $part] = $common . $ask;
        }
        if (! $asks) {
            return [];
        }
        $sys = self::drawPrompt();
        $responses = Http::pool(function ($pool) use ($asks, $headers, $model, $sys) {
            foreach ($asks as $k => $ask) {
                $pool->as((string) $k)->withHeaders($headers)->timeout(170)->post('https://api.anthropic.com/v1/messages',
                    self::body($model, $sys, [['type' => 'text', 'text' => $ask]], $k === 'scene' ? 12000 : 7000));
            }
        });
        $out = [];
        $diag = [];
        foreach ($asks as $k => $ask) {
            $r = $responses[$k] ?? null;
            $j = self::replyJson($r, $model);
            $isScene = $k === 'scene';
            $w = $isScene ? self::W : self::STEP_W;
            $h = $isScene ? self::SCENE_H : self::STEP_H;
            $frag = is_array($j) && is_string($j['svg'] ?? null) ? self::clean($j['svg'], $isScene ? 's' : 'p' . $k) : '';
            $diag[$k] = $r instanceof \Illuminate\Http\Client\Response ? ($r->status() . '/' . $r->json('stop_reason') . '/' . strlen($frag)) : 'no reply';
            if ($frag === '') {
                continue;
            }
            $ex = self::extras($j, $w, $h);
            if ($isScene) {
                $ex['hands'] = [];   // scene figures are small: their own round hands, never our big ones
            }
            $out[$isScene ? 'scene' : (int) $k] = ['svg' => $frag] + $ex;
        }
        self::$last['draw'][] = $diag;
        if (count($out) < count($asks)) {
            Log::info('Play Spark picture: parts missing', $diag);
        }

        return $out;
    }

    /**
     * The render-and-check pass: one look at the rendered picture against the plan. Returns part => problem for the
     * parts an educator could not follow (never more than 3, so a bad day costs at most one more round).
     */
    private static function check(array $plan, string $png, array $parts): array
    {
        $model = self::planModel();
        $lines = ["THE END RESULT (top): " . $plan['scene']['show']];
        foreach ($plan['steps'] as $i => $st) {
            if (in_array($i, $parts, true)) {
                $lines[] = 'STEP ' . ($i + 1) . ': "' . $st['caption'] . '" - should show: ' . $st['show'];
            }
        }
        $sys = "You review instruction pictures for a child care app before educators see them. Be strict about what matters to an "
            . "educator following the picture and relaxed about style.\n"
            . "Reject a part only when: the action in its caption is not visible; a key material is missing, unrecognisable or the wrong "
            . "count or colour; something is nonsense (floating people or objects, limbs detached or through bodies, shapes that are not "
            . "anything); hands are missing where a hand does the action; or something is unsafe for young children. In a close-up step "
            . "panel, arms reaching in from the panel edge are normal (the person is outside the frame): do not reject them.\n"
            . 'Return ONLY JSON: {"scene":{"ok":true,"fix":""},"steps":[{"step":1,"ok":true,"fix":""}]} where fix names the exact problem '
            . 'and what to draw instead, in one sentence.';
        $r = Http::withHeaders(self::headers())->timeout(90)->post('https://api.anthropic.com/v1/messages', self::body($model, $sys, [
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => base64_encode($png)]],
            ['type' => 'text', 'text' => "The plan:\n" . implode("\n", $lines) . "\n\nReview the picture against it."],
        ], 1500));
        $j = self::replyJson($r, $model);
        if (! $j) {
            return [];
        }
        $bad = [];
        if (is_array($j['scene'] ?? null) && ($j['scene']['ok'] ?? true) === false && in_array('scene', $parts, true)) {
            $bad['scene'] = mb_substr(trim((string) ($j['scene']['fix'] ?? '')), 0, 300);
        }
        foreach (is_array($j['steps'] ?? null) ? $j['steps'] : [] as $st) {
            $i = (int) ($st['step'] ?? 0) - 1;
            if (is_array($st) && ($st['ok'] ?? true) === false && in_array($i, $parts, true)) {
                $bad[$i] = mb_substr(trim((string) ($st['fix'] ?? '')), 0, 300);
            }
        }

        return array_slice($bad, 0, 3, true);
    }

    /** The structured extras of one part, checked and clamped to its canvas. */
    private static function extras(array $j, int $w, int $h): array
    {
        $num = fn ($v, $lo, $hi, $d = 0) => is_numeric($v) ? max($lo, min($hi, (float) $v)) : $d;
        $o = ['hands' => [], 'arrows' => [], 'labels' => [], 'marks' => []];
        foreach (array_slice(is_array($j['hands'] ?? null) ? $j['hands'] : [], 0, 4) as $x) {
            if (! is_array($x) || ! is_numeric($x['x'] ?? null) || ! is_numeric($x['y'] ?? null)) {
                continue;
            }
            $o['hands'][] = ['x' => $num($x['x'], -20, $w + 20), 'y' => $num($x['y'], -20, $h + 20), 'angle' => $num($x['angle'] ?? 0, -720, 720),
                'pose' => in_array($x['pose'] ?? '', ['grip', 'open', 'point', 'pinch'], true) ? $x['pose'] : 'open',
                'skin' => self::hex($x['skin'] ?? '') ?: '#E8B896', 'sleeve' => self::hex($x['sleeve'] ?? '') ?: '#5B8DEF',
                'reach' => (is_numeric($x['reach'] ?? null) && (float) $x['reach'] <= 0) ? 0.0 : -1.0,   // -1 = to the edge (hand())
                'size' => $num($x['size'] ?? 1, 0.35, 2.2, 1)];
        }
        foreach (array_slice(is_array($j['arrows'] ?? null) ? $j['arrows'] : [], 0, 3) as $x) {
            if (! is_array($x) || ! is_numeric($x['x1'] ?? null) || ! is_numeric($x['y1'] ?? null) || ! is_numeric($x['x2'] ?? null) || ! is_numeric($x['y2'] ?? null)) {
                continue;
            }
            $o['arrows'][] = ['x1' => $num($x['x1'], 0, $w), 'y1' => $num($x['y1'], 0, $h), 'x2' => $num($x['x2'], 0, $w), 'y2' => $num($x['y2'], 0, $h),
                'bend' => $num($x['bend'] ?? 0, -1, 1)];
        }
        foreach (array_slice(is_array($j['labels'] ?? null) ? $j['labels'] : [], 0, 3) as $x) {
            $t = is_array($x) ? self::plainWords((string) ($x['text'] ?? ''), 22) : '';
            if ($t === '' || ! is_numeric($x['x'] ?? null) || ! is_numeric($x['y'] ?? null)) {
                continue;
            }
            $o['labels'][] = ['text' => $t, 'x' => $num($x['x'], 0, $w), 'y' => $num($x['y'], 0, $h)];
        }
        foreach (array_slice(is_array($j['marks'] ?? null) ? $j['marks'] : [], 0, 8) as $x) {
            $t = is_array($x) ? (string) ($x['text'] ?? '') : '';
            if (! preg_match('/^[A-Za-z0-9]{1,2}$/', $t) || ! is_numeric($x['x'] ?? null) || ! is_numeric($x['y'] ?? null)) {
                continue;
            }
            $o['marks'][] = ['text' => $t, 'x' => $num($x['x'], 0, $w), 'y' => $num($x['y'], 0, $h), 'size' => $num($x['size'] ?? 20, 8, 48, 20),
                'color' => self::hex($x['color'] ?? '') ?: '#1E293B'];
        }

        return $o;
    }

    /** Short plain words only (a name tag): letters, digits, spaces and a little punctuation. */
    private static function plainWords(string $t, int $max): string
    {
        $t = trim(preg_replace('/\s+/', ' ', preg_replace("/[^\\p{L}\\p{N} '\\-&]/u", '', $t)));

        return mb_substr($t, 0, $max);
    }

    private static function hex($c): ?string
    {
        $c = trim((string) (is_scalar($c) ? $c : ''));

        return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $c) ? strtoupper($c) : null;
    }

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

    /* ── our own drawing: hands, arrows, tags, marks ───────────────────── */

    private static function shade(string $hex, float $f): string
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        $c = array_map(fn ($i) => (int) max(0, min(255, round(hexdec(substr($h, $i, 2)) * $f))), [0, 2, 4]);

        return sprintf('#%02X%02X%02X', ...$c);
    }

    /**
     * A hand from our own library, fingers along +x before rotation; (x,y) is the palm centre. Size 1 ≈ 60 px wrist
     * to fingertip. The forearm (skin wrist, then the sleeve) runs back along -x for `reach` px.
     */
    public static function hand(array $h, int $w = self::STEP_W, int $ht = self::STEP_H): string
    {
        if ((float) $h['reach'] < 0) {   // run the forearm back to the canvas edge behind the hand (+30 px, clipped)
            $a = deg2rad((float) $h['angle'] + 180);
            [$ux, $uy] = [cos($a), sin($a)];
            $t = 2000.0;
            if (abs($ux) > 1e-6) {
                $t = min($t, (($ux > 0 ? $w : 0) - $h['x']) / $ux);
            }
            if (abs($uy) > 1e-6) {
                $t = min($t, (($uy > 0 ? $ht : 0) - $h['y']) / $uy);
            }
            $h['reach'] = max(30, min(600, (max(0, $t) + 30) / max(0.35, (float) $h['size']) - 20));
        }
        $skin = $h['skin'];
        $line = self::shade($skin, 0.72);
        $sl = $h['sleeve'];
        $s = 'stroke="' . $line . '" stroke-width="1.8" stroke-linejoin="round"';
        $fill = 'fill="' . $skin . '"';
        $cap = fn ($x1, $y1, $x2, $y2, $w) => '<line x1="' . $x1 . '" y1="' . $y1 . '" x2="' . $x2 . '" y2="' . $y2 . '" stroke="' . $line . '" stroke-width="' . ($w + 3.6) . '" stroke-linecap="round"/>'
            . '<line x1="' . $x1 . '" y1="' . $y1 . '" x2="' . $x2 . '" y2="' . $y2 . '" stroke="' . $skin . '" stroke-width="' . $w . '" stroke-linecap="round"/>';
        $reach = (float) $h['reach'];
        $arm = '';
        if ($reach > 2) {
            $arm = $cap(-10, 0, -22, 0, 20)   // wrist
                . '<rect x="' . round(-20 - $reach, 1) . '" y="-14" width="' . round($reach, 1) . '" height="28" rx="12" fill="' . $sl . '" stroke="' . self::shade($sl, 0.72) . '" stroke-width="1.8"/>'
                . '<rect x="-24" y="-14" width="6" height="28" rx="3" fill="' . self::shade($sl, 0.85) . '"/>';
        }
        switch ($h['pose']) {
            case 'grip':   // a fist seen from the side, knuckles forward, thumb wrapped over the top
                $g = '<rect x="-14" y="-15" width="30" height="30" rx="12" ' . $fill . ' ' . $s . '/>'
                    . '<path d="M10 -8 h6 M10 -1 h7 M10 6 h6" stroke="' . $line . '" stroke-width="1.6" stroke-linecap="round" fill="none"/>'
                    . $cap(-8, -14, 9, -13, 8);
                break;
            case 'point':
                $g = '<rect x="-14" y="-12" width="26" height="27" rx="11" ' . $fill . ' ' . $s . '/>'
                    . $cap(8, -7, 34, -7, 8)
                    . '<path d="M6 2 h6 M6 8 h5" stroke="' . $line . '" stroke-width="1.6" stroke-linecap="round" fill="none"/>'
                    . $cap(-6, -11, 6, -15, 8);
                break;
            case 'pinch':
                $g = '<rect x="-14" y="-12" width="24" height="26" rx="11" ' . $fill . ' ' . $s . '/>'
                    . '<path d="M4 -6 Q20 -14 30 -4" stroke="' . $line . '" stroke-width="11.6" stroke-linecap="round" fill="none"/>'
                    . '<path d="M4 -6 Q20 -14 30 -4" stroke="' . $skin . '" stroke-width="8" stroke-linecap="round" fill="none"/>'
                    . '<path d="M-4 9 Q16 10 29 1" stroke="' . $line . '" stroke-width="11.6" stroke-linecap="round" fill="none"/>'
                    . '<path d="M-4 9 Q16 10 29 1" stroke="' . $skin . '" stroke-width="8" stroke-linecap="round" fill="none"/>'
                    . '<path d="M6 4 h4" stroke="' . $line . '" stroke-width="1.4" stroke-linecap="round"/>';
                break;
            default:   // open: a flat hand, four fingers and a thumb
                $g = $cap(8, -11, 30, -12, 7.2) . $cap(8, -3.6, 34, -4, 7.4) . $cap(8, 3.6, 33, 4, 7.4) . $cap(8, 10.8, 27, 12, 6.8)
                    . '<rect x="-14" y="-15" width="26" height="30" rx="11" ' . $fill . ' ' . $s . '/>'
                    . '<rect x="-10" y="-12" width="22" height="24" rx="9" ' . $fill . '/>'
                    . $cap(-2, -14, 12, -25, 7.6);
        }
        $sc = round((float) $h['size'], 3);

        return '<g transform="translate(' . round($h['x'], 1) . ' ' . round($h['y'], 1) . ') rotate(' . round($h['angle'], 1) . ') scale(' . $sc . ')">' . $arm . $g . '</g>';
    }

    /** A bold movement arrow (white halo so it reads on any colour). */
    public static function arrow(array $a, float $k = 1.0): string
    {
        [$x1, $y1, $x2, $y2] = [$a['x1'], $a['y1'], $a['x2'], $a['y2']];
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $len = sqrt($dx * $dx + $dy * $dy);
        if ($len < 12) {
            return '';
        }
        $cx = ($x1 + $x2) / 2 - $dy * $a['bend'] * 0.5;
        $cy = ($y1 + $y2) / 2 + $dx * $a['bend'] * 0.5;
        $head = 14 * $k;
        $tx = $x2 - $cx;
        $ty = $y2 - $cy;
        $tl = sqrt($tx * $tx + $ty * $ty) ?: 1;
        $ux = $tx / $tl;
        $uy = $ty / $tl;
        $bx = $x2 - $ux * $head;   // the shaft stops where the head starts
        $by = $y2 - $uy * $head;
        $p = fn ($v) => round($v, 1);
        $d = 'M' . $p($x1) . ' ' . $p($y1) . ' Q' . $p($cx) . ' ' . $p($cy) . ' ' . $p($bx) . ' ' . $p($by);
        $tri = $p($x2) . ',' . $p($y2) . ' ' . $p($bx - $uy * $head * 0.62) . ',' . $p($by + $ux * $head * 0.62) . ' ' . $p($bx + $uy * $head * 0.62) . ',' . $p($by - $ux * $head * 0.62);

        return '<path d="' . $d . '" fill="none" stroke="#FFFFFF" stroke-width="' . round(11 * $k, 1) . '" stroke-linecap="round" stroke-opacity="0.9"/>'
            . '<polygon points="' . $tri . '" fill="#FFFFFF" stroke="#FFFFFF" stroke-width="' . round(6 * $k, 1) . '" stroke-linejoin="round"/>'
            . '<path d="' . $d . '" fill="none" stroke="#FF5A4E" stroke-width="' . round(5.5 * $k, 1) . '" stroke-linecap="round"/>'
            . '<polygon points="' . $tri . '" fill="#FF5A4E" stroke="#FF5A4E" stroke-width="2" stroke-linejoin="round"/>';
    }

    /**
     * A name tag pointing at (x,y). Tries above, below, left and right of the point and takes the first spot that stays
     * inside the canvas and clear of $taken (other tags, the number badge); with no clear spot the tag is left out -
     * a missing tag is better than two on top of each other. Adds its box to $taken.
     */
    public static function tag(array $l, int $w, int $h, string $font, float $k, array &$taken): string
    {
        $e = fn ($s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $text = mb_strtoupper(mb_substr($l['text'], 0, 1)) . mb_substr($l['text'], 1);
        $fs = 13 * $k;
        $tw = mb_strlen($text) * $fs * 0.6 + 16 * $k;
        $th = 22 * $k;
        $gap = 26 * $k;
        [$x, $y] = [$l['x'], $l['y']];
        $spots = [[$x - $tw / 2, $y - $gap - $th], [$x - $tw / 2, $y + $gap], [$x - $gap - $tw, $y - $th / 2], [$x + $gap, $y - $th / 2],
            [$x - $tw * 0.15, $y - $gap - $th], [$x - $tw * 0.85, $y - $gap - $th], [$x - $tw * 0.15, $y + $gap], [$x - $tw * 0.85, $y + $gap]];
        $box = null;
        foreach ($spots as [$tx, $ty]) {
            if ($tx < 2 || $ty < 2 || $tx + $tw > $w - 2 || $ty + $th > $h - 2) {
                continue;
            }
            $hit = false;
            foreach ($taken as [$ax, $ay, $aw, $ah]) {
                if ($tx < $ax + $aw + 4 && $tx + $tw + 4 > $ax && $ty < $ay + $ah + 4 && $ty + $th + 4 > $ay) {
                    $hit = true;
                    break;
                }
            }
            if (! $hit) {
                $box = [$tx, $ty];
                break;
            }
        }
        if (! $box) {
            return '';
        }
        [$tx, $ty] = $box;
        $taken[] = [$tx, $ty, $tw, $th];
        $p = fn ($v) => round($v, 1);
        $ex = max($tx + 8, min($tx + $tw - 8, $x));   // where the line meets the tag
        $ey = max($ty, min($ty + $th, $y));

        return '<line x1="' . $p($x) . '" y1="' . $p($y) . '" x2="' . $p($ex) . '" y2="' . $p($ey) . '" stroke="#0B1026" stroke-width="' . $p(1.6 * $k) . '"/>'
            . '<circle cx="' . $p($x) . '" cy="' . $p($y) . '" r="' . $p(3.6 * $k) . '" fill="#0B1026" stroke="#FFFFFF" stroke-width="' . $p(1.5 * $k) . '"/>'
            . '<rect x="' . $p($tx) . '" y="' . $p($ty) . '" width="' . $p($tw) . '" height="' . $p($th) . '" rx="' . $p($th / 2) . '" fill="#FFFFFF" stroke="#0B1026" stroke-width="' . $p(1.5 * $k) . '"/>'
            . '<text x="' . $p($tx + $tw / 2) . '" y="' . $p($ty + $th / 2 + $fs * 0.36) . '" ' . $font . ' font-size="' . $p($fs) . '" font-weight="700" fill="#0B1026" text-anchor="middle">' . $e($text) . '</text>';
    }

    private static function overlay(array $p, int $w, int $h, string $font, float $k = 1.0): string
    {
        $e = fn ($s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $o = '';
        foreach ($p['marks'] ?? [] as $m) {
            $o .= '<text x="' . round($m['x'], 1) . '" y="' . round($m['y'] + $m['size'] * 0.36, 1) . '" ' . $font . ' font-size="' . round($m['size'], 1)
                . '" font-weight="800" fill="' . $m['color'] . '" text-anchor="middle">' . $e($m['text']) . '</text>';
        }
        foreach ($p['hands'] ?? [] as $hd) {
            $o .= self::hand($hd, $w, $h);
        }
        foreach ($p['arrows'] ?? [] as $a) {
            $o .= self::arrow($a, $k);
        }
        $taken = [$w === self::W ? [0, 0, 200, 56] : [0, 0, 44, 44]];   // the badge / the step number
        foreach ($p['labels'] ?? [] as $l) {
            $o .= self::tag($l, $w, $h, $font, $k, $taken);
        }

        return $o;
    }

    /* ── layout ─────────────────────────────────────────────────────────── */

    /**
     * The end result on top, then the steps two by two (392 px panels), each with its number and a caption of up to
     * three lines. Groups with a transform, never nested <svg> (Imagick, the fallback renderer, draws those wrongly).
     * Each part is clipped to its own frame by OUR clipPath (the AI may not use one), and white is still painted over
     * the gutters for renderers that ignore clipping.
     */
    public static function compose(string $title, array $plan, array $parts): string
    {
        $W = self::W;
        $steps = [];
        foreach ($plan['steps'] as $i => $st) {
            if (isset($parts[$i])) {
                $steps[] = $parts[$i] + ['caption' => $st['caption']];
            }
        }
        $n = count($steps);
        $e = fn ($s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $font = 'font-family="Inter, Lato, Arial, Helvetica, sans-serif"';
        $gap = 16;
        $pw = ($W - $gap) / 2;
        $scale = $pw / self::STEP_W;
        $ph = self::STEP_H * $scale;
        $capFs = 16.5;
        $capLh = 21;
        $wrapAt = 44;
        $wrapped = array_map(fn ($st) => self::wrap($st['caption'], $wrapAt, 3), $steps);
        $headTop = self::SCENE_H + 14;
        $gridTop = $headTop + 34;
        $rows = (int) ceil($n / 2);
        $rowCap = [];
        for ($r = 0; $r < $rows; $r++) {
            $rowCap[$r] = max(1, count($wrapped[$r * 2] ?? []), count($wrapped[$r * 2 + 1] ?? [])) * $capLh + 14;
        }
        $pos = [];
        $y = $gridTop;
        for ($r = 0; $r < $rows; $r++) {
            $inRow = min(2, $n - $r * 2);
            for ($c = 0; $c < $inRow; $c++) {
                $x = $inRow === 1 ? ($W - $pw) / 2 : $c * ($pw + $gap);
                $pos[] = [round($x, 2), round($y, 2)];
            }
            $y += $ph + $rowCap[$r] + 14;
        }
        $H = (int) ceil($n ? $y : self::SCENE_H);

        $s = '<svg xmlns="http://www.w3.org/2000/svg" data-ps="' . self::VERSION . '" viewBox="0 0 ' . $W . ' ' . $H . '" width="' . $W . '" height="' . $H . '" role="img">'
            . '<title>' . $e($title . ': the end result' . ($n ? ' and ' . $n . ' steps. ' . implode(' ', array_map(fn ($i, $st) => ($i + 1) . '. ' . $st['caption'], array_keys($steps), $steps)) : '')) . '</title>'
            . '<g stroke="none">'   // Imagick's renderer outlines shapes that name no stroke; browsers do not
            . '<rect x="0" y="0" width="' . $W . '" height="' . $H . '" fill="#FFFFFF"/>'
            . '<defs><clipPath id="kc-s"><rect x="0" y="0" width="' . $W . '" height="' . self::SCENE_H . '"/></clipPath>'
            . '<clipPath id="kc-p"><rect x="0" y="0" width="' . self::STEP_W . '" height="' . self::STEP_H . '" rx="' . round(12 / $scale, 2) . '"/></clipPath></defs>'
            . '<g clip-path="url(#kc-s)"><g>' . $parts['scene']['svg'] . '</g>'
            . self::overlay($parts['scene'], $W, self::SCENE_H, $font, 1.15) . '</g>';
        $white = fn ($x, $y, $w, $h) => '<rect x="' . round($x, 2) . '" y="' . round($y, 2) . '" width="' . round($w, 2) . '" height="' . round($h, 2) . '" fill="#FFFFFF"/>';
        $s .= $white(-400, self::SCENE_H, $W + 800, $H - self::SCENE_H + 400) . $white(-400, -400, 400, $H + 800) . $white($W, -400, 400, $H + 800)
            . $white(-400, -400, $W + 800, 400);
        foreach ($steps as $i => $st) {
            [$x, $y] = $pos[$i];
            $s .= '<g transform="translate(' . $x . ' ' . $y . ') scale(' . round($scale, 5) . ')" clip-path="url(#kc-p)">' . $st['svg']
                . self::overlay($st, self::STEP_W, self::STEP_H, $font) . '</g>'
                // trim anything drawn outside the panel
                . $white($x - $gap, $y - $gap - 2, $pw + 2 * $gap, $gap + 2) . $white($x - $gap, $y + $ph, $pw + 2 * $gap, $gap + 4)
                . $white($x - $gap, $y - 2, $gap, $ph + 4) . $white($x + $pw, $y - 2, $gap, $ph + 4);
        }
        // the captions go on last, over any trimmed overflow
        $s .= '<rect x="14" y="14" width="168" height="30" rx="15" fill="#0B1026" fill-opacity="0.88"/>'
            . '<circle cx="31" cy="29" r="7" fill="#FFC531"/>'
            . '<text x="46" y="34" ' . $font . ' font-size="14" font-weight="700" fill="#FFFFFF">The end result</text>';
        if ($n) {
            $s .= '<rect x="0" y="' . $headTop . '" width="' . $W . '" height="2" fill="#E6EAF2"/>'
                . '<text x="2" y="' . ($headTop + 25) . '" ' . $font . ' font-size="16" font-weight="800" fill="#0B1026">How to do it</text>';
        }
        $colors = ['#FF5A4E', '#E8A400', '#3D8BFF', '#22B37C'];
        foreach ($steps as $i => $st) {
            [$x, $y] = $pos[$i];
            $s .= '<rect x="' . $x . '" y="' . $y . '" width="' . round($pw, 2) . '" height="' . round($ph, 2) . '" rx="12" fill="none" stroke="#D7DDE8" stroke-width="1.5"/>'
                . '<circle cx="' . ($x + 20) . '" cy="' . ($y + 20) . '" r="14" fill="' . $colors[$i % 4] . '" stroke="#FFFFFF" stroke-width="2"/>'
                . '<text x="' . ($x + 20) . '" y="' . ($y + 25.5) . '" ' . $font . ' font-size="16" font-weight="800" fill="#FFFFFF" text-anchor="middle">' . ($i + 1) . '</text>';
            foreach ($wrapped[$i] as $li => $line) {
                $s .= '<text x="' . ($x + 4) . '" y="' . round($y + $ph + 22 + $li * $capLh, 1) . '" ' . $font . ' font-size="' . $capFs . '" font-weight="' . ($li ? '600' : '700') . '" fill="#1E293B">'
                    . ($li === 0 ? '<tspan font-weight="800" fill="' . $colors[$i % 4] . '">' . ($i + 1) . '.</tspan> ' : '') . $e($line) . '</text>';
            }
        }

        return $s . '</g></svg>';
    }

    private static function wrap(string $t, int $max, int $maxLines = 2): array
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
        if (count($lines) > $maxLines) {
            $rest = implode(' ', array_slice($lines, $maxLines - 1));
            $lines = array_slice($lines, 0, $maxLines - 1);
            $lines[] = mb_strlen($rest) > $max ? mb_substr($rest, 0, $max - 1) . '…' : $rest;
        }

        return $lines;
    }

    /* ── PNG ────────────────────────────────────────────────────────────── */

    /**
     * PNG for email and the check pass. resvg (tools/svg-png, a Node script) draws exactly what browsers draw;
     * ImageMagick's own SVG renderer (the fallback) loses stroked arms and turns smiles into open mouths. resvg is a
     * separate process, so it only runs where processes may start (the queue worker; web PHP has no proc_open).
     */
    public static function png(string $svg, int $width = 1200): ?string
    {
        $blob = self::pngResvg($svg, $width);
        if ($blob !== null) {
            return $blob;
        }
        if (! class_exists(\Imagick::class)) {
            return null;
        }
        try {
            $im = new \Imagick();
            $im->setBackgroundColor(new \ImagickPixel('#FFFFFF'));
            $im->setResolution(144, 144);
            $im->readImageBlob('<?xml version="1.0" encoding="UTF-8"?>' . $svg);
            $im->setImageFormat('png');
            $im->thumbnailImage($width, 0);
            $im->stripImage();
            $blob = $im->getImageBlob();
            $im->clear();

            return strlen($blob) > 2000 ? $blob : null;
        } catch (\Throwable $e) {
            Log::info('Play Spark picture PNG failed', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return null;
        }
    }

    private static function pngResvg(string $svg, int $width): ?string
    {
        $script = base_path('tools/svg-png/svg-png.cjs');
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (! is_file($script) || ! is_dir(dirname($script) . '/node_modules/@resvg') || ! function_exists('proc_open') || in_array('proc_open', $disabled, true)) {
            return null;
        }
        try {
            $p = new \Symfony\Component\Process\Process([(string) (env('KT_NODE_BIN') ?: '/usr/bin/node'), $script, (string) $width], dirname($script));
            $p->setInput($svg);
            $p->setTimeout(30);
            $p->run();
            $out = $p->getOutput();
            if (! $p->isSuccessful() || strlen($out) < 2000 || substr($out, 0, 8) !== "\x89PNG\r\n\x1a\n") {
                Log::info('Play Spark picture resvg failed', ['error' => mb_substr($p->getErrorOutput(), 0, 200)]);

                return null;
            }

            return $out;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
