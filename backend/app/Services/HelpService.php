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
 */
final class HelpService
{
    private string $basePath;

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
            'agency_admin', 'centre_director', 'auditor' => 'director',
            'educator' => 'educator',
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
            return empty($a['roles']) || in_array($role, $a['roles'], true);
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

        foreach ($files as $file) {
            $raw = file_get_contents($file);
            if ($raw === false) continue;

            $parsed = $this->parseFrontMatter($raw);
            $slug = Str::slug(pathinfo($file, PATHINFO_FILENAME));

            $articles[] = [
                'slug' => $slug,
                'title' => $parsed['meta']['title'] ?? Str::title(str_replace('-', ' ', $slug)),
                'category' => $parsed['meta']['category'] ?? 'General',
                'order' => isset($parsed['meta']['order']) ? (int) $parsed['meta']['order'] : 999,
                'body' => $parsed['body'],
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
