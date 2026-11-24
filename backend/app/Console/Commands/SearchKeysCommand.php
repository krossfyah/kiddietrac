<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\SearchKeywords;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * search-keys:health - nightly. Retires words a screen no longer shows, prints the state of the learned search words
 * (App\Support\SearchKeywords) and emails the platform admins ONLY when something is wrong: no browser has reported
 * a screen for 48 hours (learning has stopped), or a role people use has no words at all.
 *
 * search-keys:health --import=<file> seeds the table once from the 2026-11-09 crawl (parent-portal/js/kt-search-keys.js).
 */
final class SearchKeysCommand extends Command
{
    protected $signature = 'search-keys:health {--no-mail : print only} {--import= : seed from a kt-search-keys.js file}';
    protected $description = 'Keep the global search words current: retire stale ones, alert when learning stops';

    public function handle(): int
    {
        if ($this->option('import')) return $this->import((string) $this->option('import'));

        $retired = SearchKeywords::retire();
        $h = SearchKeywords::health();
        $this->line("live {$h['live_total']} · learned 7d {$h['learned_7d']} · retired now {$retired} · last report "
            . ($h['hours_since_report'] === null ? 'never' : $h['hours_since_report'] . 'h ago'));
        foreach ($h['by_role'] as $r) $this->line("  {$r['role']}: {$r['phrases']} words on {$r['screens']} screens");

        $fail = [];
        if ($h['hours_since_report'] === null || $h['hours_since_report'] >= 48) {
            $fail[] = ['line' => 'No browser has reported a screen\'s words for ' . ($h['hours_since_report'] === null ? 'ever' : $h['hours_since_report'] . ' hours')
                . '. Learning has stopped: check search-widget.js (harvest) and POST /diag/search-words.'];
        }
        $have = collect($h['by_role'])->pluck('role')->all();
        foreach (['agency_admin', 'educator', 'guardian'] as $r) {
            if (! in_array($r, $have, true)) $fail[] = ['line' => "The $r role has no search words at all."];
        }
        if (! $fail) { $this->info('Search words OK.'); return self::SUCCESS; }
        foreach ($fail as $f) $this->error('  ' . $f['line']);
        Log::warning('[SEARCH-WORDS] ' . count($fail) . ' problem(s)', array_column($fail, 'line'));
        if (! $this->option('no-mail')) {
            KtIntegrityCheck::alertPlatformAdmins('[KiddieTrac] Search words — ' . count($fail) . ' problem(s)',
                'The global search learns the words on each screen as people use the portal. Something has stopped that (Platform → Server health → Search words).',
                ['Problems' => $fail]);
        }

        return self::FAILURE;
    }

    private function import(string $file): int
    {
        $s = (string) @file_get_contents($file);
        $at = strpos($s, '= ');
        $idx = $at === false ? null : json_decode(rtrim(substr($s, $at + 2), " ;\n"), true);
        if (! is_array($idx)) { $this->error('Not a kt-search-keys.js file.'); return self::FAILURE; }
        $rows = [];
        $now = now();
        foreach ($idx as $role => $screens) {
            foreach ($screens as $hash => $list) {
                foreach ($list as $row) {
                    $rows[] = ['role' => $role, 'hash' => $hash, 'phrase' => mb_substr((string) $row[0], 0, 64), 'tab' => mb_substr((string) ($row[1] ?? ''), 0, 64),
                        'source' => 'crawl', 'first_seen_at' => $now, 'last_seen_at' => $now, 'seen_count' => 1];
                }
            }
        }
        $n = 0;
        foreach (array_chunk($rows, 500) as $c) $n += DB::table('search_keywords')->insertOrIgnore($c);
        Cache::forever('kt_sk_version', (string) $now->getTimestampMs());
        $this->info("Imported $n of " . count($rows) . ' phrases.');

        return self::SUCCESS;
    }
}
