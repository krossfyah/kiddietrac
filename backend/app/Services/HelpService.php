<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Reads help articles from /resources/help/{role}/*.md and returns
 * structured data for the API. Articles use simple front-matter:
 *
 *   ---
 *   title: How to add a new child
 *   category: Getting Started
 *   order: 10
 *   ---
 *   # How to add a new child
 *   ...
 *
 * Translations (2026-10-22): `slug.fr.md`, `slug.es.md`, `slug.hi.md` beside `slug.md`.
 * The reader's own language (users.locale) is used when that file exists, otherwise the
 * English article. A translation carries its own title and body; the category, order and
 * `roles:` always come from the English file, so who can read what never differs by
 * language. The slug is the English one, so links, training and analytics are unchanged.
 */
final class HelpService
{
    private string $basePath;

    private const LOCALES = ['fr', 'es', 'hi'];

    private ?string $locale = null;

    public function __construct()
    {
        $this->basePath = resource_path('help');
    }

    /**
     * Get all articles available to a given role.
     * Each role gets its own + shared articles.
     */
    public function listForRole(string $role): array
    {
        // v22p98: auditor maps to the director library (compliance/audit/reporting
        // content lives there) — NOT to 'parent' as the old default did, which
        // showed auditors irrelevant family articles.
        $roleFolder = match ($role) {
            'agency_admin', 'centre_director', 'auditor', 'reseller_admin' => 'director',
            'educator' => 'educator',
            'home_visitor' => 'home_visitor',
            'sales_rep' => 'sales',
            'guardian' => 'parent',
            default => 'parent',
        };

        $articles = [
            ...$this->loadFolder($roleFolder),
            ...$this->loadFolder('shared'),
        ];

        // v22p98: role-relevance — hide guides for features the role doesn't have.
        $articles = $this->filterForRole($articles, $role);

        // Sort by category then order
        usort($articles, function ($a, $b) {
            $catCmp = strcmp($a['category'], $b['category']);
            if ($catCmp !== 0) return $catCmp;
            return ($a['order'] ?? 999) <=> ($b['order'] ?? 999);
        });

        return $articles;
    }

    /**
     * Get a single article by slug, ensuring the user's role can access it.
     */
    public function getArticle(string $slug, string $role): ?array
    {
        $articles = $this->listForRole($role);

        foreach ($articles as $article) {
            if ($article['slug'] === $slug) {
                return $article;
            }
        }

        return null;
    }

    /**
     * Returns all articles grouped by category, suitable for sidebar nav.
     */
    public function categorizedForRole(string $role): array
    {
        $articles = $this->listForRole($role);
        $grouped = [];

        foreach ($articles as $article) {
            $grouped[$article['category']] ??= [];
            $grouped[$article['category']][] = [
                'slug' => $article['slug'],
                'title' => $article['title'],
                'order' => $article['order'] ?? 999,
            ];
        }

        return $grouped;
    }

    /**
     * THE FEW ARTICLES THAT MATTER, not the whole library (2026-10-28, Anthony: "how do we lower the AI usage as its
     * chewing up like crazy"). Measured: every "Ask AI" question sent all 183 articles - 522,531 characters, ~181,000
     * input tokens - which was 98% of all AI tokens used. Now each question is matched against the role's articles
     * (title and slug count most, then category, then the body) and only the best $max go, each capped, plus a
     * one-line-per-article INDEX so the assistant can still point at any guide.
     *
     * @return array{index:string, articles:array}
     */
    public function relevantForRole(string $role, string $question, int $max = 6, int $capChars = 8000): array
    {
        $all = $this->listForRole($role);
        $stop = array_flip(['how', 'do', 'does', 'did', 'can', 'could', 'should', 'would', 'what', 'where', 'when', 'why', 'who',
            'the', 'and', 'for', 'with', 'from', 'into', 'this', 'that', 'there', 'their', 'they', 'you', 'your', 'our', 'are',
            'was', 'were', 'is', 'it', 'its', 'my', 'me', 'i', 'a', 'an', 'to', 'of', 'in', 'on', 'or', 'be', 'if', 'not', 'any',
            'kiddietrac', 'please', 'want', 'need', 'get', 'use', 'using', 'make', 'way', 'help', 'about', 'some', 'have', 'has']);
        $stem = function (string $w): string {
            foreach (['ing', 'ies', 'ed', 'es', 's'] as $suf) {
                if (strlen($w) > strlen($suf) + 3 && str_ends_with($w, $suf)) {
                    return $suf === 'ies' ? substr($w, 0, -3) . 'y' : substr($w, 0, -strlen($suf));
                }
            }
            return $w;
        };
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($question)) as $w) {
            if (mb_strlen($w) >= 3 && ! isset($stop[$w])) {
                $words[$stem($w)] = true;
            }
        }
        $words = array_keys($words);
        $scored = [];
        foreach ($all as $a) {
            $title = mb_strtolower($a['title'] . ' ' . str_replace('-', ' ', $a['slug']));
            $cat = mb_strtolower((string) $a['category']);
            $body = mb_strtolower((string) $a['body']);
            $sc = 0;
            foreach ($words as $w) {
                if ($w === '') continue;
                if (str_contains($title, $w)) $sc += 6;
                if (str_contains($cat, $w)) $sc += 2;
                $sc += min(4, substr_count($body, $w));
            }
            if ($sc > 0) $scored[] = [$sc, $a];
        }
        usort($scored, fn ($x, $y) => $y[0] <=> $x[0]);
        $pick = array_map(fn ($x) => $x[1], array_slice($scored, 0, $max));

        $index = [];
        foreach ($all as $a) {
            $index[] = '- ' . $a['title'] . ' [' . $a['slug'] . ']';
        }
        $articles = [];
        foreach ($pick as $a) {
            $b = (string) $a['body'];
            if (mb_strlen($b) > $capChars) $b = mb_substr($b, 0, $capChars) . "\n…";
            $articles[] = "## ARTICLE: {$a['title']}\nCATEGORY: {$a['category']}\nSLUG: {$a['slug']}\n\n{$b}\n\n---\n";
        }

        return ['index' => implode("\n", $index), 'articles' => $articles];
    }

    /**
     * Get all articles' content as one searchable corpus for the AI assistant.
     * Returns string with each article's title + body separated by markers.
     */
    public function getCorpusForRole(string $role): string
    {
        $articles = $this->listForRole($role);
        $sections = [];

        foreach ($articles as $a) {
            $sections[] = "## ARTICLE: {$a['title']}\nCATEGORY: {$a['category']}\nSLUG: {$a['slug']}\n\n{$a['body']}\n\n---\n";
        }

        return implode("\n", $sections);
    }

    /**
     * v22p98 — Keep only articles relevant to the given role.
     *  • `roles:` front-matter targets an article to specific roles (e.g. agency
     *    admin / platform-only guides are hidden from a centre director).
     *  • Auditors are read-only compliance reviewers — narrow the director library
     *    to the categories that actually apply to them.
     */
    private function filterForRole(array $articles, string $role): array
    {
        $articles = array_filter($articles, function ($a) use ($role) {
            // A reseller works as agency admin inside its own agencies (2026-10-22), so it
            // also reads the guides tagged for agency admins.
            return empty($a['roles']) || in_array($role, $a['roles'], true)
                || ($role === 'reseller_admin' && in_array('agency_admin', $a['roles'], true));
        });

        if ($role === 'auditor') {
            $allowed = ['Compliance', 'Reporting', 'Getting Started', 'Troubleshooting', 'Your Account', 'Administration'];
            $auditorTitles = ['Audit log viewer', 'Compliance dashboard', 'Custom report builder', 'Compliance and reporting'];
            $articles = array_filter($articles, function ($a) use ($allowed, $auditorTitles) {
                // Compliance/reporting categories, plus a few named admin articles an auditor uses.
                return in_array($a['category'], ['Compliance', 'Reporting', 'Getting Started', 'Troubleshooting', 'Your Account'], true)
                    || in_array($a['title'], $auditorTitles, true);
            });
        }

        return array_values($articles);
    }

    private function loadFolder(string $folder): array
    {
        $path = $this->basePath.'/'.$folder;
        if (!is_dir($path)) {
            return [];
        }

        $files = glob($path.'/*.md');
        $articles = [];
        $loc = $this->locale();

        foreach ($files as $file) {
            // slug.fr.md and friends are translations of slug.md, not articles of their own.
            if (preg_match('/\.(' . implode('|', self::LOCALES) . ')\.md$/', $file)) continue;

            $raw = file_get_contents($file);
            if ($raw === false) continue;

            $parsed = $this->parseFrontMatter($raw);
            $slug = Str::slug(pathinfo($file, PATHINFO_FILENAME));

            $text = $parsed;
            $lang = 'en';
            if ($loc !== 'en') {
                $tFile = substr($file, 0, -3) . '.' . $loc . '.md';
                $tRaw = is_file($tFile) ? file_get_contents($tFile) : false;
                if ($tRaw !== false) {
                    $tParsed = $this->parseFrontMatter(str_replace("\r\n", "\n", $tRaw));
                    if (trim($tParsed['body']) !== '') {
                        $text = $tParsed;
                        $lang = $loc;
                    }
                }
            }

            $articles[] = [
                'slug' => $slug,
                'title' => $text['meta']['title'] ?? $parsed['meta']['title'] ?? Str::title(str_replace('-', ' ', $slug)),
                'category' => $parsed['meta']['category'] ?? 'General',
                'order' => isset($parsed['meta']['order']) ? (int) $parsed['meta']['order'] : 999,
                'body' => $text['body'],
                'lang' => $lang,
                'audience' => $folder, // 'director', 'educator', 'parent', 'shared'
                // v22p98: optional per-article role targeting — `roles: a, b` in the
                // front-matter restricts the article to those roles.
                'roles' => isset($parsed['meta']['roles'])
                    ? array_filter(array_map('trim', explode(',', (string) $parsed['meta']['roles'])))
                    : [],
            ];
        }

        return $articles;
    }

    /** The reader's language: users.locale of the signed-in user, English otherwise. */
    private function locale(): string
    {
        if ($this->locale === null) {
            $l = '';
            try {
                $u = request()->user();
                $l = strtolower((string) ($u->locale ?? ''));
            } catch (\Throwable $e) {
                $l = '';
            }
            $this->locale = in_array($l, self::LOCALES, true) ? $l : 'en';
        }

        return $this->locale;
    }

    /**
     * Parse simple YAML-ish front-matter at the top of a markdown file.
     * Only supports key: value, no nesting.
     */
    private function parseFrontMatter(string $content): array
    {
        if (!str_starts_with($content, "---\n")) {
            return ['meta' => [], 'body' => $content];
        }

        $end = strpos($content, "\n---\n", 4);
        if ($end === false) {
            return ['meta' => [], 'body' => $content];
        }

        $frontMatter = substr($content, 4, $end - 4);
        $body = substr($content, $end + 5);

        $meta = [];
        foreach (explode("\n", $frontMatter) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) continue;
            [$key, $value] = explode(':', $line, 2);
            $meta[trim($key)] = trim($value);
        }

        return ['meta' => $meta, 'body' => trim($body)];
    }
}
