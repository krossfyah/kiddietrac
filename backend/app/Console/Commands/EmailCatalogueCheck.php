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

        return self::SUCCESS;
    }
}
