<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\SearchController;
use App\Support\SearchRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GLOBAL SEARCH COVERAGE (2026-11-02).
 *
 * Anthony: "make the search on the portal pull up invoices, receipts (global search) - keep this
 * current with any new changes as well".
 *
 * Search queries the tables live, so it is never stale; it is INCOMPLETE when a record type is
 * added and nobody gives it a search source. This makes that noisy:
 *
 *   FAIL  a registry SOURCE with no SearchController::src<Key>() method, or a table it names that does not exist
 *   FAIL  a PRIMARY record type that is in no SOURCE, not in NOT_SEARCHABLE (with a reason) and not in KNOWN_GAPS
 *   FAIL  a table created after the baseline (resources/kt/search-table-baseline.txt) that nobody has classified
 *         (SOURCE / NOT_SEARCHABLE / KNOWN_GAPS / PLUMBING)
 *   WARN  KNOWN_GAPS — listed every run so they stay visible
 *
 *   php artisan kt:search-coverage            (emails the platform admins when it fails)
 *   php artisan kt:search-coverage --no-mail  (prints only; exit 1 on failure)
 *
 * See App\Support\SearchRegistry. Runs nightly with kt:integrity-check / kt:leak-sweep.
 */
class KtSearchCoverage extends Command
{
    protected $signature = 'kt:search-coverage {--no-mail : print only, do not email the platform admins}';
    protected $description = 'Fail when a main record type (or a new table) has no global-search source and no written reason.';

    public function handle(): int
    {
        $tables = [];
        foreach (DB::select("SELECT table_name AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'") as $r) {
            $tables[(string) $r->n] = true;
        }
        $count = function (string $t): string {
            try { return (string) DB::table($t)->count(); } catch (Throwable $e) { return '?'; }
        };

        $fail = [];
        $covered = SearchRegistry::coveredTables();

        $this->line('SEARCHABLE (' . count(SearchRegistry::SOURCES) . ' sources)');
        foreach (SearchRegistry::SOURCES as $key => $meta) {
            $method = SearchRegistry::method($key);
            $ok = method_exists(SearchController::class, $method);
            if (! $ok) $fail[] = ['line' => "source '$key' has no SearchController::$method()"];
            $bits = [];
            foreach ($meta['tables'] as $t) {
                if (! isset($tables[$t])) { $fail[] = ['line' => "source '$key' names table '$t', which does not exist"]; $bits[] = "$t(MISSING)"; continue; }
                $bits[] = $t . '(' . $count($t) . ')';
            }
            $this->line(sprintf('  %-4s %-18s %-22s %s%s', $ok ? 'ok' : 'FAIL', $key, $meta['label'], implode(' ', $bits),
                ! empty($meta['billing']) ? '  [billing roles; feature ' . ($meta['feature'] ?: '-') . ']' : ''));
        }

        $this->line('');
        $this->line('PRIMARY RECORD TYPES (' . count(SearchRegistry::PRIMARY) . ')');
        $nCov = $nNot = $nGap = 0;
        foreach (SearchRegistry::PRIMARY as $t => $what) {
            if (isset($covered[$t])) { $state = 'searchable'; $why = implode(', ', $covered[$t]); $nCov++; }
            elseif (isset(SearchRegistry::NOT_SEARCHABLE[$t])) { $state = 'not searchable'; $why = SearchRegistry::NOT_SEARCHABLE[$t]; $nNot++; }
            elseif (isset(SearchRegistry::KNOWN_GAPS[$t])) { $state = 'GAP (known)'; $why = SearchRegistry::KNOWN_GAPS[$t]; $nGap++; }
            else { $state = 'FAIL'; $why = 'no search source and no reason'; $fail[] = ['line' => "$t ($what): no search source and no NOT_SEARCHABLE / KNOWN_GAPS line"]; }
            if (! isset($tables[$t])) $why .= ' [table not in this database]';
            $this->line(sprintf('  %-15s %-28s %-42s %s', $state, $t, $what, $why));
        }

        // Tables created after the baseline must be classified, so a new feature cannot slip past.
        $baselineFile = base_path(SearchRegistry::BASELINE_FILE);
        $baseline = is_file($baselineFile)
            ? array_fill_keys(array_filter(array_map('trim', file($baselineFile, FILE_IGNORE_NEW_LINES) ?: []), fn ($l) => $l !== '' && $l[0] !== '#'), true)
            : null;
        $this->line('');
        if ($baseline === null) {
            $fail[] = ['line' => 'baseline file missing: ' . SearchRegistry::BASELINE_FILE];
            $this->line('NEW TABLES: baseline file missing (' . SearchRegistry::BASELINE_FILE . ')');
        } else {
            $new = array_values(array_filter(array_keys($tables), fn ($t) => ! isset($baseline[$t])));
            sort($new);
            $this->line('NEW TABLES since the baseline (' . count($new) . ')');
            foreach ($new as $t) {
                if (isset($covered[$t])) $s = 'searchable';
                elseif (isset(SearchRegistry::NOT_SEARCHABLE[$t])) $s = 'not searchable: ' . SearchRegistry::NOT_SEARCHABLE[$t];
                elseif (isset(SearchRegistry::KNOWN_GAPS[$t])) $s = 'GAP (known): ' . SearchRegistry::KNOWN_GAPS[$t];
                elseif (isset(SearchRegistry::PLUMBING[$t])) $s = 'plumbing: ' . SearchRegistry::PLUMBING[$t];
                else { $s = 'FAIL unclassified'; $fail[] = ['line' => "new table '$t' (" . $count($t) . " rows) is not classified in App\\Support\\SearchRegistry (SOURCES / NOT_SEARCHABLE / KNOWN_GAPS / PLUMBING)"]; }
                $this->line(sprintf('  %-34s %s', $t, $s));
            }
        }

        $this->line('');
        $this->line(sprintf('Summary: %d searchable, %d not searchable (with reasons), %d known gaps, %d failures.', $nCov, $nNot, $nGap, count($fail)));

        if (! $fail) {
            $this->info('Search coverage OK.');
            return self::SUCCESS;
        }
        foreach ($fail as $f) $this->error('  ' . $f['line']);
        Log::critical('[SEARCH-COVERAGE] ' . count($fail) . ' failure(s)', array_column($fail, 'line'));
        if (! $this->option('no-mail')) {
            KtIntegrityCheck::alertPlatformAdmins(
                '[KiddieTrac] Search coverage — ' . count($fail) . ' record type(s) not searchable',
                'A record type has no global-search source and no written reason, so staff cannot find it from the search box. Add a source (or a NOT_SEARCHABLE reason) in App\Support\SearchRegistry.',
                ['Not covered' => $fail]
            );
        }
        return self::FAILURE;
    }
}
