<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\EmailCatalogue;
use Illuminate\Console\Command;

/**
 * Prove the email catalogue still describes the code.
 *
 * The catalogue exists so somebody can review what the system sends. That only holds while
 * it is COMPLETE — and a list of emails is exactly the kind of document that rots quietly,
 * because adding an email is a one-line change somewhere else entirely and nobody thinks
 * of the inventory.
 *
 * So the inventory is checkable: this walks app/ for EmailTemplate::wrap sites and fails if
 * one lives in a file no catalogue entry points at. It does not try to be clever about
 * which entry — file-level coverage is enough to catch a whole new email appearing, which
 * is the failure that matters.
 *
 * Run it in CI, or by hand before a review.
 */
final class EmailCatalogueCheck extends Command
{
    protected $signature = 'email:catalogue {--check : Exit non-zero if the code has emails the catalogue does not cover}';

    protected $description = 'List every email the system sends, and verify the catalogue still covers the code';

    /**
     * Files that contain the string but do not SEND: the shell builder, the editable
     * registry (which the catalogue reaches by key, not by path), and the catalogue and
     * this checker themselves — both of which name the function in their own source.
     * Listed here so every exclusion is visible in one place rather than being a silent
     * hole in a check whose entire job is to have no holes.
     */
    private const NOT_SENDERS = [
        'Services/EmailTemplate.php',
        'Support/EmailTemplates.php',
        'Support/EmailCatalogue.php',
        'Console/Commands/EmailCatalogueCheck.php',
    ];

    public function handle(): int
    {
        $entries = EmailCatalogue::all();
        $covered = EmailCatalogue::coveredFiles();

        // ── walk the code ──
        $found = [];
        $base = base_path('app');
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
        foreach ($it as $f) {
            if (! $f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            $src = @file_get_contents($f->getPathname());
            if ($src === false || strpos($src, 'EmailTemplate::wrap') === false) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
            $found[$rel] = substr_count($src, 'EmailTemplate::wrap');
        }
        ksort($found);

        if (! $this->option('check')) {
            foreach (EmailCatalogue::grouped() as $aud => $rows) {
                if (! $rows) {
                    continue;
                }
                $this->newLine();
                $this->line('<comment>' . (EmailCatalogue::AUDIENCES[$aud] ?? $aud) . '</comment>');
                foreach ($rows as $r) {
                    $this->line(sprintf('  %-26s %-38s %s',
                        $r['key'],
                        mb_strimwidth((string) $r['name'], 0, 36, '…'),
                        $r['preview'] === 'render' ? 'editable + preview'
                            : ($r['preview'] === 'sample' ? 'sample can be sent' : 'documented')));
                }
            }
            $this->newLine();
            $this->info(sprintf('%d emails catalogued across %d files (%d wrap sites in code).',
                count($entries), count($covered), array_sum($found)));

            return self::SUCCESS;
        }

        // ── the check ──
        $uncovered = [];
        foreach ($found as $rel => $n) {
            $hit = false;
            foreach ($covered as $c) {
                if ($c === $rel) {
                    $hit = true;
                    break;
                }
            }
            /* Two files are the machinery itself, not emails: EmailTemplate builds the
               shell, EmailTemplates is the editable registry the catalogue already points
               into by key. Excluded deliberately, and named so the exclusion is visible
               rather than a silent hole. */
            if (! $hit && ! in_array($rel, self::NOT_SENDERS, true)) {
                $uncovered[$rel] = $n;
            }
        }

        /* EVERY WRAP SITE NAMES ITS EMAIL (2026-10-06). An email can only be worded in
           Settings → Email templates if EmailTemplate::wrap() can tell which email is being
           built, and it learns that from the calling method (EmailOverrides::SITES). A new
           sender that is not listed there still sends, unchanged, but its wording cannot be
           edited — so it is reported here, method by method. */
        $unmapped = [];
        foreach (array_keys($found) as $rel) {
            if (in_array($rel, self::NOT_SENDERS, true) || $rel === 'Support/TemplatedEmails.php') {
                continue;
            }
            foreach (self::wrapMethods(base_path('app/' . $rel)) as $site) {
                if (! array_key_exists($site, \App\Support\EmailOverrides::SITES)) {
                    $unmapped[] = $rel . ' → ' . $site;
                }
            }
        }
        if ($unmapped) {
            $this->warn('These senders are not mapped in App\Support\EmailOverrides::SITES, so their wording cannot be edited:');
            foreach (array_unique($unmapped) as $u) {
                $this->line('  ' . $u);
            }
        }

        if ($uncovered) {
            $this->error('The catalogue does not cover these files — an email was added without being listed:');
            foreach ($uncovered as $rel => $n) {
                $this->line(sprintf('  %-58s %d wrap site(s)', $rel, $n));
            }
            $this->newLine();
            $this->line('Add an entry to app/Support/EmailCatalogue.php with its source file:line.');

            return self::FAILURE;
        }

        $this->info(sprintf('Catalogue covers all %d files that compose email (%d entries, %d wrap sites).',
            count($found), count($entries), array_sum($found)));

        return $unmapped ? self::FAILURE : self::SUCCESS;
    }

    /** "Class@method" for every named method in $path that calls EmailTemplate::wrap. */
    private static function wrapMethods(string $path): array
    {
        $src = (string) @file_get_contents($path);
        $toks = token_get_all($src);
        $out = [];
        $class = '';
        $stack = [];
        $pending = null;
        $n = count($toks);
        for ($i = 0; $i < $n; $i++) {
            $t = $toks[$i];
            if (is_array($t)) {
                if ($t[0] === T_CLASS && $class === '') {
                    for ($j = $i + 1; $j < $n; $j++) {
                        if (is_array($toks[$j]) && $toks[$j][0] === T_STRING) { $class = $toks[$j][1]; break; }
                    }
                }
                if ($t[0] === T_FUNCTION) {
                    for ($j = $i + 1; $j < $n; $j++) {
                        if (is_array($toks[$j]) && $toks[$j][0] === T_STRING) { $pending = $toks[$j][1]; break; }
                        if ($toks[$j] === '(') { break; }
                    }
                }
                if (in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $stack[] = null;
                    continue;
                }
                if (in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                    && substr($t[1], -13) === 'EmailTemplate'
                    && isset($toks[$i + 1], $toks[$i + 2]) && is_array($toks[$i + 1]) && $toks[$i + 1][0] === T_DOUBLE_COLON
                    && is_array($toks[$i + 2]) && $toks[$i + 2][1] === 'wrap') {
                    foreach (array_reverse($stack) as $fn) {
                        if ($fn !== null) { $out[] = $class . '@' . $fn; break; }
                    }
                }
            } elseif ($t === '{') {
                $stack[] = $pending;
                $pending = null;
            } elseif ($t === '}') {
                array_pop($stack);
            }
        }

        return array_values(array_unique($out));
    }
}
