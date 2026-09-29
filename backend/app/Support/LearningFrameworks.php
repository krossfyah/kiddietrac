<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Learning frameworks (2026-09-29).
 *
 * Anthony asked for "more learning frameworks" after the CuePilot comparison: KiddieTrac
 * was Ontario-only (How Does Learning Happen) in every prompt, report card and report.
 *
 * Each agency picks ONE framework (agencies.settings.learning_framework). A framework is a
 * set of "areas" -- HDLH's four foundations, EYFS's seven areas, and so on. Everything that
 * used to say belonging/wellbeing/engagement/expression now asks this class for the
 * agency's areas:
 *   - observations: the AI links an observation to areas (stored in hdlh_milestones as
 *     {foundation: <area key>, ...} -- the column name is historical) and the row records
 *     observations.framework
 *   - report cards: one narrative per area (report_cards.narratives JSON); HDLH cards also
 *     keep filling the four legacy narrative_* columns so old screens and PDFs still work
 *   - the framework-gaps report counts linked areas per child
 * The seven activity domains of lesson plans (social_emotional, physical, ...) are a
 * separate, framework-neutral vocabulary and do not change.
 *
 * Area keys are stable identifiers; labels are for people. Never rename a key once
 * records exist -- rename the label.
 */
final class LearningFrameworks
{
    public const DEFAULT = 'HDLH';

    public static function all(): array
    {
        return [
            'HDLH' => [
                'name' => 'How Does Learning Happen?', 'short' => 'HDLH', 'region' => 'Ontario',
                'areas' => [
                    'belonging'  => ['Belonging', 'Relationships, connection, identity, family and community.'],
                    'wellbeing'  => ['Well-being', 'Health, safety, self-regulation and body awareness.'],
                    'engagement' => ['Engagement', 'Exploration, curiosity, focus, problem-solving and persistence.'],
                    'expression' => ['Expression', 'Communication, language, art, music and dramatic play.'],
                ],
            ],
            'ELECT' => [
                'name' => 'Early Learning for Every Child Today', 'short' => 'ELECT', 'region' => 'Ontario (continuum)',
                'areas' => [
                    'social'        => ['Social', 'Play with others, cooperation, empathy and belonging in a group.'],
                    'emotional'     => ['Emotional', 'Expressing and regulating feelings, self-concept and resilience.'],
                    'communication' => ['Communication, language and literacy', 'Listening, speaking, early reading and writing.'],
                    'cognitive'     => ['Cognitive', 'Problem-solving, memory, early math and representation.'],
                    'physical'      => ['Physical', 'Gross and fine motor skills, health and self-care.'],
                ],
            ],
            'BC_ELF' => [
                'name' => 'British Columbia Early Learning Framework', 'short' => 'BC ELF', 'region' => 'British Columbia',
                'areas' => [
                    'wellbeing_belonging' => ['Well-being and belonging', 'Feeling safe, healthy, connected and part of a community.'],
                    'living_inquiry'      => ['Engagement in living inquiry and playing', 'Curiosity, exploration, play and wondering about the world.'],
                    'communication'       => ['Communication and literacies', 'Language, stories, symbols and many ways of making meaning.'],
                    'identities'          => ['Identities, social responsibilities and diversity', 'Who I am, caring for others, and valuing difference.'],
                ],
            ],
            'FLIGHT' => [
                'name' => 'Flight: Alberta’s Early Learning and Care Framework', 'short' => 'Flight', 'region' => 'Alberta',
                'areas' => [
                    'wellbeing'     => ['Well-being', 'Physical, emotional and social well-being.'],
                    'play'          => ['Play and playfulness', 'Imagination, creativity, exploration and joyful play.'],
                    'communication' => ['Communication and literacies', 'Language, gesture, stories, symbols and early literacy.'],
                    'diversity'     => ['Diversity and social responsibility', 'Belonging, fairness, caring for others and the environment.'],
                ],
            ],
            'QC' => [
                'name' => 'Accueillir la petite enfance', 'short' => 'Québec', 'region' => 'Québec',
                'areas' => [
                    'physical_motor'   => ['Physical and motor', 'Movement, coordination, health and daily routines.'],
                    'cognitive'        => ['Cognitive', 'Reasoning, attention, memory, early math and science.'],
                    'language'         => ['Language', 'Oral language, communication and emergent literacy.'],
                    'social_emotional' => ['Social and emotional', 'Relationships, feelings, self-esteem and self-regulation.'],
                ],
            ],
            'ELOF' => [
                'name' => 'Head Start Early Learning Outcomes Framework', 'short' => 'ELOF', 'region' => 'United States',
                'areas' => [
                    'approaches'       => ['Approaches to learning', 'Initiative, curiosity, persistence and emotional/behavioural self-regulation.'],
                    'social_emotional' => ['Social and emotional development', 'Relationships with adults and peers, emotional functioning, sense of identity.'],
                    'language_literacy'=> ['Language and literacy', 'Attending, understanding, communicating, vocabulary and emergent literacy.'],
                    'cognition'        => ['Cognition', 'Mathematics development and scientific reasoning.'],
                    'perceptual_motor' => ['Perceptual, motor and physical development', 'Gross and fine motor skills, health, safety and nutrition.'],
                ],
            ],
            'EYFS' => [
                'name' => 'Early Years Foundation Stage', 'short' => 'EYFS', 'region' => 'England',
                'areas' => [
                    'communication_language' => ['Communication and language', 'Listening, attention, understanding and speaking.'],
                    'psed'                   => ['Personal, social and emotional development', 'Self-regulation, managing self and building relationships.'],
                    'physical'               => ['Physical development', 'Gross and fine motor skills.'],
                    'literacy'               => ['Literacy', 'Comprehension, word reading and writing.'],
                    'mathematics'            => ['Mathematics', 'Number and numerical patterns.'],
                    'understanding_world'    => ['Understanding the world', 'People, culture, communities and the natural world.'],
                    'expressive_arts'        => ['Expressive arts and design', 'Creating with materials, being imaginative and expressive.'],
                ],
            ],
            'HIGHSCOPE' => [
                'name' => 'HighScope Curriculum', 'short' => 'HighScope', 'region' => 'International',
                'areas' => [
                    'approaches'     => ['Approaches to learning', 'Initiative, planning, engagement, problem-solving and reflection.'],
                    'social_emotional' => ['Social and emotional development', 'Self-identity, relationships, community and conflict resolution.'],
                    'physical_health'  => ['Physical development and health', 'Gross and fine motor skills, body awareness and healthy behaviour.'],
                    'language_literacy'=> ['Language, literacy and communication', 'Speaking, vocabulary, phonological awareness, reading and writing.'],
                    'mathematics'      => ['Mathematics', 'Number, shapes, patterns, measuring and data.'],
                    'creative_arts'    => ['Creative arts', 'Art, music, movement and pretend play.'],
                    'science_tech'     => ['Science and technology', 'Observing, predicting, experimenting and using tools.'],
                    'social_studies'   => ['Social studies', 'Diversity, community roles, decision-making and ecology.'],
                ],
            ],
            'CUSTOM' => [
                'name' => 'Our own framework', 'short' => 'Custom', 'region' => 'Your agency',
                'areas' => [],   // filled from settings.learning_framework.custom_areas
            ],
        ];
    }

    /* Which area an observation counts toward when it carries no explicit area links
       (manual observations, the no-AI fallback, and every observation before 2026-09-29):
       platform activity domain -> framework area. */
    public const DOMAIN_MAP = [
        'HDLH' => ['social_emotional' => 'belonging', 'physical' => 'wellbeing', 'self_care' => 'wellbeing', 'cognitive' => 'engagement',
                   'outdoor' => 'engagement', 'language_literacy' => 'expression', 'creative_arts' => 'expression'],
        'ELECT' => ['social_emotional' => 'social', 'physical' => 'physical', 'self_care' => 'physical', 'outdoor' => 'physical',
                    'cognitive' => 'cognitive', 'creative_arts' => 'cognitive', 'language_literacy' => 'communication'],
        'BC_ELF' => ['social_emotional' => 'wellbeing_belonging', 'physical' => 'wellbeing_belonging', 'self_care' => 'wellbeing_belonging',
                     'cognitive' => 'living_inquiry', 'outdoor' => 'living_inquiry', 'creative_arts' => 'living_inquiry', 'language_literacy' => 'communication'],
        'FLIGHT' => ['social_emotional' => 'wellbeing', 'physical' => 'wellbeing', 'self_care' => 'wellbeing', 'cognitive' => 'play',
                     'creative_arts' => 'play', 'outdoor' => 'play', 'language_literacy' => 'communication'],
        'QC' => ['social_emotional' => 'social_emotional', 'physical' => 'physical_motor', 'self_care' => 'physical_motor', 'outdoor' => 'physical_motor',
                 'cognitive' => 'cognitive', 'creative_arts' => 'cognitive', 'language_literacy' => 'language'],
        'ELOF' => ['social_emotional' => 'social_emotional', 'physical' => 'perceptual_motor', 'self_care' => 'perceptual_motor', 'outdoor' => 'perceptual_motor',
                   'cognitive' => 'cognition', 'creative_arts' => 'approaches', 'language_literacy' => 'language_literacy'],
        'EYFS' => ['social_emotional' => 'psed', 'physical' => 'physical', 'self_care' => 'physical', 'outdoor' => 'understanding_world',
                   'cognitive' => 'mathematics', 'creative_arts' => 'expressive_arts', 'language_literacy' => 'communication_language'],
        'HIGHSCOPE' => ['social_emotional' => 'social_emotional', 'physical' => 'physical_health', 'self_care' => 'physical_health', 'outdoor' => 'science_tech',
                        'cognitive' => 'mathematics', 'creative_arts' => 'creative_arts', 'language_literacy' => 'language_literacy'],
    ];

    /** Areas an observation counts toward in a resolved framework. */
    public static function areasOf(array $fw, ?string $domain, $links): array
    {
        $keys = array_column($fw['areas'], 'key');
        $links = is_string($links) ? (json_decode($links, true) ?: []) : (array) $links;
        $out = [];
        foreach ($links as $m) {
            $f = is_array($m) ? (string) ($m['foundation'] ?? '') : '';
            if (in_array($f, $keys, true)) $out[$f] = true;
        }
        if (! $out && $domain) {
            $mapped = self::DOMAIN_MAP[$fw['key']][$domain] ?? null;
            if ($mapped && in_array($mapped, $keys, true)) $out[$mapped] = true;
        }
        return array_keys($out);
    }

    /** A sensible starting point when an agency has not chosen. */
    public static function defaultFor(?string $province, ?string $country = null): string
    {
        $p = strtoupper(trim((string) $province));
        $c = strtoupper(trim((string) $country));
        if (in_array($c, ['US', 'USA', 'UNITED STATES'], true)) return 'ELOF';
        if (in_array($c, ['GB', 'UK', 'UNITED KINGDOM', 'ENGLAND'], true)) return 'EYFS';
        return match ($p) {
            'BC', 'BRITISH COLUMBIA' => 'BC_ELF',
            'AB', 'ALBERTA' => 'FLIGHT',
            'QC', 'QUEBEC', 'QUÉBEC' => 'QC',
            default => self::DEFAULT,
        };
    }

    /** The agency's framework, resolved: {key, name, short, region, areas:[{key,label,hint}], chosen:bool}. */
    public static function forAgency(?int $agencyId): array
    {
        $agency = $agencyId ? DB::table('agencies')->where('id', $agencyId)->first(['settings', 'province', 'country']) : null;
        $settings = $agency ? (json_decode((string) $agency->settings, true) ?: []) : [];
        $cfg = is_array($settings['learning_framework'] ?? null) ? $settings['learning_framework'] : [];
        $all = self::all();
        $key = strtoupper((string) ($cfg['key'] ?? ''));
        $chosen = isset($all[$key]);
        if (! $chosen) {
            $key = self::defaultFor($agency->province ?? null, $agency->country ?? null);
        }
        $areas = $all[$key]['areas'];
        if ($key === 'CUSTOM') {
            $areas = [];
            foreach ((array) ($cfg['custom_areas'] ?? []) as $a) {
                if (! empty($a['key']) && ! empty($a['label'])) $areas[$a['key']] = [$a['label'], (string) ($a['hint'] ?? '')];
            }
            if (! $areas) {                       // an empty custom framework falls back rather than breaking
                $key = self::DEFAULT;
                $areas = $all[$key]['areas'];
            }
        }
        $f = $all[$key];

        return [
            'key' => $key, 'name' => $key === 'CUSTOM' ? (string) ($cfg['custom_name'] ?? $f['name']) : $f['name'],
            'short' => $f['short'], 'region' => $f['region'], 'chosen' => $chosen,
            'areas' => array_map(fn ($k, $v) => ['key' => $k, 'label' => $v[0], 'hint' => $v[1]], array_keys($areas), $areas),
        ];
    }

    /** Area label by key for a resolved framework (falls back to a readable key). */
    public static function label(array $fw, string $areaKey): string
    {
        foreach ($fw['areas'] as $a) if ($a['key'] === $areaKey) return $a['label'];
        return ucwords(str_replace('_', ' ', $areaKey));
    }

    /** Make a stable key from a custom area label. */
    public static function slug(string $label): string
    {
        $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', \Illuminate\Support\Str::ascii($label)), '_'));
        return substr($s ?: 'area', 0, 40);
    }

    /* The platform's framework-neutral activity domains, used by observations and lesson
       plans. The AI observation prompt used to ask for "language" and "creative_expression",
       which nothing else in the platform recognises. */
    public const DOMAINS = ['social_emotional', 'physical', 'language_literacy', 'cognitive', 'creative_arts', 'self_care', 'outdoor'];
}
