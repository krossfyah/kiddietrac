<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Ontario curriculum library units (2026-11-03).
 *
 * A unit is one JSON document (curriculum_templates.unit_data, kind = 'unit'):
 *   slug, title, age_group, age_label, theme, season, weeks, summary, big_questions[],
 *   goals[{text, foundations[], continuum[]}],
 *   activities[{id, title, domain, foundations[], materials[], steps[], time_of_day, safety}],
 *   schedule[{week, focus, days{monday..friday: [activity ids]}}],
 *   observe[], family[], inclusion[], french{vocab[[en, fr]], tips[]}, safety_notes[]
 *
 * Foundations are the HDLH keys LearningFrameworks uses (belonging, wellbeing, engagement,
 * expression), so a planned activity counts toward the same areas as an observation.
 * clean() is the ONE normaliser: the library loader and an agency editing its copy both go
 * through it, so nothing else can be stored.
 */
final class CurriculumUnits
{
    public const FOUNDATIONS = ['belonging', 'wellbeing', 'engagement', 'expression'];
    public const FOUNDATION_LABELS = ['belonging' => 'Belonging', 'wellbeing' => 'Well-being', 'engagement' => 'Engagement', 'expression' => 'Expression'];
    public const DOMAINS = ['social_emotional', 'physical', 'language_literacy', 'cognitive', 'creative_arts', 'self_care', 'outdoor'];
    public const AGES = ['infant', 'toddler', 'preschool', 'school_age'];
    public const AGE_LABELS = ['infant' => 'Infant', 'toddler' => 'Toddler', 'preschool' => 'Preschool', 'school_age' => 'Kindergarten / school-age'];
    public const SEASONS = ['any', 'fall', 'winter', 'spring', 'summer'];
    public const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
    public const TIMES = ['morning', 'afternoon', 'outdoor', 'circle', 'any'];

    /** The stored form of a unit. Unknown keys are dropped; every string is trimmed and capped. */
    public static function clean(array $u): array
    {
        $s = fn ($v, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($v ?? ''))), 0, $max);
        $list = function ($v, int $maxItems, int $maxLen) use ($s): array {
            $out = [];
            foreach ((array) ($v ?? []) as $x) {
                if (is_array($x)) continue;
                $x = $s($x, $maxLen);
                if ($x !== '') $out[] = $x;
                if (count($out) >= $maxItems) break;
            }
            return $out;
        };
        $found = function ($v): array {
            $out = [];
            foreach ((array) ($v ?? []) as $f) {
                $f = strtolower(str_replace(['-', ' '], '', (string) $f));
                if ($f === 'wellbeing' || $f === 'well_being') $f = 'wellbeing';
                if (in_array($f, self::FOUNDATIONS, true) && ! in_array($f, $out, true)) $out[] = $f;
            }
            return $out;
        };

        $age = in_array($u['age_group'] ?? '', self::AGES, true) ? $u['age_group'] : 'preschool';
        $season = in_array($u['season'] ?? '', self::SEASONS, true) ? $u['season'] : 'any';

        $acts = [];
        $ids = [];
        foreach ((array) ($u['activities'] ?? []) as $i => $a) {
            if (! is_array($a)) continue;
            $title = $s($a['title'] ?? '', 80);
            if ($title === '') continue;
            $id = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($a['id'] ?? ''))) ?: ('a' . ($i + 1));
            while (isset($ids[$id])) $id .= 'x';
            $ids[$id] = true;
            $acts[] = [
                'id' => $id,
                'title' => $title,
                'domain' => in_array($a['domain'] ?? '', self::DOMAINS, true) ? $a['domain'] : null,
                'foundations' => $found($a['foundations'] ?? []),
                'materials' => $list($a['materials'] ?? [], 10, 120),
                'steps' => $list($a['steps'] ?? [], 8, 300),
                'time_of_day' => in_array($a['time_of_day'] ?? '', self::TIMES, true) ? $a['time_of_day'] : 'any',
                'safety' => $s($a['safety'] ?? '', 300),
            ];
            if (count($acts) >= 40) break;
        }

        $weeks = max(1, min(6, (int) ($u['weeks'] ?? 0) ?: count((array) ($u['schedule'] ?? [])) ?: 1));
        $schedule = [];
        $byWeek = [];
        foreach ((array) ($u['schedule'] ?? []) as $w) {
            if (is_array($w) && ($n = (int) ($w['week'] ?? 0)) >= 1) $byWeek[$n] = $w;
        }
        for ($n = 1; $n <= $weeks; $n++) {
            $w = $byWeek[$n] ?? [];
            $days = [];
            foreach (self::DAYS as $d) {
                $days[$d] = [];
                foreach ((array) ($w['days'][$d] ?? []) as $ref) {
                    $ref = (string) $ref;
                    if (isset($ids[$ref]) && count($days[$d]) < 4) $days[$d][] = $ref;
                }
            }
            $schedule[] = ['week' => $n, 'focus' => $s($w['focus'] ?? '', 160), 'days' => $days];
        }

        $goals = [];
        foreach ((array) ($u['goals'] ?? []) as $g) {
            if (is_string($g)) $g = ['text' => $g];
            if (! is_array($g)) continue;
            $t = $s($g['text'] ?? '', 300);
            if ($t === '') continue;
            $goals[] = ['text' => $t, 'foundations' => $found($g['foundations'] ?? []), 'continuum' => $list($g['continuum'] ?? [], 6, 80)];
            if (count($goals) >= 10) break;
        }

        $vocab = [];
        foreach ((array) ($u['french']['vocab'] ?? []) as $p) {
            if (! is_array($p) || count($p) < 2) continue;
            $en = $s($p[0], 60); $fr = $s($p[1], 80);
            if ($en !== '' && $fr !== '') $vocab[] = [$en, $fr];
            if (count($vocab) >= 30) break;
        }

        return [
            'slug' => preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($u['slug'] ?? ''))),
            'title' => $s($u['title'] ?? '', 120) ?: 'Untitled unit',
            'age_group' => $age,
            'age_label' => $s($u['age_label'] ?? '', 60),
            'theme' => $s($u['theme'] ?? '', 60),
            'season' => $season,
            'weeks' => $weeks,
            'summary' => $s($u['summary'] ?? '', 800),
            'big_questions' => $list($u['big_questions'] ?? [], 5, 200),
            'goals' => $goals,
            'activities' => $acts,
            'schedule' => $schedule,
            'observe' => $list($u['observe'] ?? [], 12, 300),
            'family' => $list($u['family'] ?? [], 10, 300),
            'inclusion' => $list($u['inclusion'] ?? [], 10, 300),
            'french' => ['vocab' => $vocab, 'tips' => $list($u['french']['tips'] ?? [], 8, 300)],
            'safety_notes' => $list($u['safety_notes'] ?? [], 6, 300),
        ];
    }

    /** Problems that make a library unit unfit to publish (structure only; safety is lint()). */
    public static function problems(array $u): array
    {
        $p = [];
        if ($u['slug'] === '') $p[] = 'no slug';
        if (count($u['activities']) < 4) $p[] = 'fewer than 4 activities';
        $covered = [];
        foreach ($u['goals'] as $g) foreach ($g['foundations'] as $f) $covered[$f] = true;
        foreach (self::FOUNDATIONS as $f) if (! isset($covered[$f])) $p[] = "goals do not cover $f";
        foreach ($u['schedule'] as $w) {
            foreach (self::DAYS as $d) {
                if (count($w['days'][$d]) < 1) $p[] = "week {$w['week']} $d has no activity";
            }
        }
        foreach ($u['activities'] as $a) {
            if (! $a['foundations']) $p[] = "activity '{$a['title']}' has no foundation";
            if (! $a['steps']) $p[] = "activity '{$a['title']}' has no steps";
        }
        if (! $u['observe']) $p[] = 'no observation prompts';
        if (! $u['family']) $p[] = 'no family ideas';
        if (! $u['inclusion']) $p[] = 'no inclusion notes';
        if (count($u['french']['vocab']) < 5) $p[] = 'fewer than 5 French words';

        return $p;
    }

    /**
     * Words a reviewer must look at: choking hazards for under-3s, nuts anywhere, unsafe
     * materials. A hit is not automatically wrong ("quartered grapes", "nut-free") - the
     * library loader prints them so a person decides; under-3 units FAIL on the hard list.
     */
    public static function lint(array $u): array
    {
        $young = in_array($u['age_group'], ['infant', 'toddler'], true);
        $hard = '/\b(beads?|marbles?|coins?|pom-?poms?|googly|popcorn|marshmallows?|raisins?|hot ?dogs?|gum|glitter|sequins?|pebbles?|push ?pins?|thumb ?tacks?|batter(y|ies)|button batter|balloons?|cotton balls?|dried (beans?|rice|pasta|lentils)|whole grapes?|nuts?|peanuts?|almonds?|cashews?|plastic bags?)\b/i';
        $soft = '/\b(grapes?|cherry tomato(es)?|carrots?|popcorn|nuts?|peanut|tree nut|honey|string|ribbon|cord|yarn|small|tiny|scissors|toothpicks?|skewers?|craft sticks?|glue gun|hot glue|latex)\b/i';
        $hits = [];
        $scan = function (string $where, string $text) use (&$hits, $young, $hard, $soft) {
            if ($text === '') return;
            if ($young && preg_match_all($hard, $text, $m)) {
                foreach (array_unique($m[0]) as $w) {
                    // "nut-free", "peanut-free", "no nuts" are the safe phrasing, not a hazard.
                    if (preg_match('/\b(no|nut-free|peanut-free|free of|never|avoid|not|without)\b[^.]{0,30}' . preg_quote($w, '/') . '|' . preg_quote($w, '/') . '-free/i', $text)) continue;
                    $hits[] = ['level' => 'hard', 'where' => $where, 'word' => $w, 'text' => mb_substr($text, 0, 160)];
                }
            }
            if (preg_match_all($soft, $text, $m)) {
                foreach (array_unique($m[0]) as $w) {
                    $hits[] = ['level' => 'review', 'where' => $where, 'word' => $w, 'text' => mb_substr($text, 0, 160)];
                }
            }
        };
        foreach ($u['activities'] as $a) {
            $scan("activity {$a['id']} title", $a['title']);
            foreach ($a['materials'] as $i => $m) $scan("activity {$a['id']} material", $m);
            foreach ($a['steps'] as $i => $m) $scan("activity {$a['id']} step", $m);
        }
        foreach ($u['family'] as $m) $scan('family idea', $m);

        return $hits;
    }

    /** Distinct foundations used by a unit's activities (for the filter column). */
    public static function foundationsOf(array $u): array
    {
        $out = [];
        foreach ($u['activities'] as $a) foreach ($a['foundations'] as $f) $out[$f] = true;
        foreach ($u['goals'] as $g) foreach ($g['foundations'] as $f) $out[$f] = true;

        return array_values(array_intersect(self::FOUNDATIONS, array_keys($out)));
    }

    /** One week of a unit as lesson_plans.plan_data days (activity rows the planner understands). */
    public static function weekDays(array $u, int $week): array
    {
        $byId = [];
        foreach ($u['activities'] as $a) $byId[$a['id']] = $a;
        $w = null;
        foreach ($u['schedule'] as $x) if ((int) $x['week'] === $week) $w = $x;
        $days = array_fill_keys(self::DAYS, []);
        if (! $w) return $days;
        foreach (self::DAYS as $d) {
            foreach ($w['days'][$d] as $ref) {
                if (! isset($byId[$ref])) continue;
                $a = $byId[$ref];
                $days[$d][] = [
                    'time' => '',
                    'title' => mb_substr($a['title'], 0, 200),
                    'domain' => $a['domain'],
                    'foundations' => $a['foundations'],
                    'notes' => self::notesFor($a),
                    'unit_activity' => $a['id'],
                ];
            }
        }

        return $days;
    }

    /** Materials, steps and safety folded into the planner's notes field. */
    public static function notesFor(array $a): string
    {
        $parts = [];
        if ($a['materials']) $parts[] = 'Materials: ' . implode(', ', $a['materials']) . '.';
        if ($a['steps']) {
            $n = 0;
            $parts[] = 'Steps: ' . implode(' ', array_map(function ($st) use (&$n) { $n++; return $n . ') ' . rtrim($st, '.') . '.'; }, $a['steps']));
        }
        if ($a['safety'] !== '') $parts[] = 'Safety: ' . $a['safety'];

        return mb_substr(implode("\n", $parts), 0, 1500);
    }

    /** What a lesson plan remembers about the unit it came from (planner banner; family ideas for parents). */
    public static function planMeta(array $u, int $unitId, int $week): array
    {
        $focus = '';
        foreach ($u['schedule'] as $x) if ((int) $x['week'] === $week) $focus = $x['focus'];

        return [
            'id' => $unitId,
            'slug' => $u['slug'],
            'title' => $u['title'],
            'week' => $week,
            'of' => (int) $u['weeks'],
            'focus' => $focus,
            'observe' => array_slice($u['observe'], 0, 8),
            'family' => array_slice($u['family'], 0, 6),
            'french' => array_slice($u['french']['vocab'], 0, 14),
        ];
    }

    /** A lesson plan's unit block, sanitised on save (the planner sends back what it was given). */
    public static function cleanPlanMeta($m): ?array
    {
        if (! is_array($m) || empty($m['title'])) return null;
        $s = fn ($v, int $max) => mb_substr(trim((string) ($v ?? '')), 0, $max);
        $list = function ($v, int $n, int $len) use ($s) {
            $o = [];
            foreach ((array) $v as $x) { if (is_string($x) && ($x = $s($x, $len)) !== '') $o[] = $x; if (count($o) >= $n) break; }
            return $o;
        };
        $vocab = [];
        foreach ((array) ($m['french'] ?? []) as $p) {
            if (is_array($p) && count($p) >= 2) $vocab[] = [$s($p[0], 60), $s($p[1], 80)];
            if (count($vocab) >= 14) break;
        }

        return [
            'id' => (int) ($m['id'] ?? 0) ?: null,
            'slug' => preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($m['slug'] ?? ''))),
            'title' => $s($m['title'], 120),
            'week' => max(1, (int) ($m['week'] ?? 1)),
            'of' => max(1, (int) ($m['of'] ?? 1)),
            'focus' => $s($m['focus'] ?? '', 160),
            'observe' => $list($m['observe'] ?? [], 8, 300),
            'family' => $list($m['family'] ?? [], 6, 300),
            'french' => $vocab,
        ];
    }

    /** The columns beside unit_data (what the library filters and the old screens read). */
    public static function columns(array $u): array
    {
        $materials = [];
        foreach ($u['activities'] as $a) foreach ($a['materials'] as $m) $materials[mb_strtolower($m)] = $m;
        $f = self::foundationsOf($u);

        return [
            'kind' => 'unit',
            'slug' => $u['slug'] ?: null,
            'title' => $u['title'],
            'description' => $u['summary'],
            'age_band' => $u['age_group'],
            'theme' => $u['theme'] ?: null,
            'season' => $u['season'],
            'weeks' => $u['weeks'],
            'foundations' => implode(',', $f),
            'materials' => json_encode(array_values(array_slice($materials, 0, 60))),
            'hdlh_domains' => json_encode(array_map(fn ($k) => self::FOUNDATION_LABELS[$k], $f)),
            'activities' => json_encode(array_map(fn ($a) => $a['title'], $u['activities'])),
            'unit_data' => json_encode($u, JSON_UNESCAPED_UNICODE),
        ];
    }
}
