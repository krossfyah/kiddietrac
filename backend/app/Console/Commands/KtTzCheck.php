<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * php artisan kt:tz-check — no UTC "today" in the code (2026-10-21).
 *
 * The server clock is UTC and MariaDB's NOW()/CURDATE() run on the host's MST, so each of
 * these names the wrong day for part of every evening (from 8pm Toronto it is already
 * tomorrow in UTC):
 *
 *     now()->toDateString()   today()   Carbon::today()   date('Y-m-d')
 *     now()->format('Y-m-d')  now()->startOfDay()/startOfWeek()/startOfMonth()
 *     CURDATE()  CURRENT_DATE  NOW()  (inside SQL)
 *
 * Use App\Support\AgencyTime instead: today() for a 'Y-m-d', todayDate() for date
 * arithmetic, boundaryUtc() for comparing with stored instants, dayRange()/scopeDay() for
 * "everything that happened on this agency day". With no agency id they use the request's
 * agency (ResolveAgencyTimezone middleware).
 *
 * A line that genuinely wants UTC (a log stamp, a file name nobody reads, a server-side
 * idempotency key) carries the marker `tz-ok` in a comment on the same line.
 * Exit code 1 when anything is found, so a deploy script can refuse.
 */
class KtTzCheck extends Command
{
    protected $signature = 'kt:tz-check {--all : also list files on the allowlist}';
    protected $description = 'List code that computes "today" in UTC/server time instead of the agency timezone.';

    /** Files whose dates are sample data or that implement the helpers themselves. */
    private const ALLOW = [
        'Support/AgencyTime.php', 'Support/EmailPreviews.php', 'Support/EmailSampleData.php', 'Support/EmailTemplates.php',
        'Console/Commands/KtTzCheck.php', 'Console/Commands/SeedDemo.php', 'Console/Commands/SeedDemoDaily.php',
    ];

    private const PATTERNS = [
        '/(?<![\w>:\\\\])(?:\\\\?Illuminate\\\\Support\\\\Carbon::|\\\\?Carbon\\\\Carbon::|Carbon::)?(?:now|today)\(\)->toDateString\(\)/' => 'UTC date: use AgencyTime::today()',
        '/(?<![\w>:\\\\])(?:Carbon::)?today\(\)/' => 'UTC today(): use AgencyTime::today() / todayDate()',
        '/(?<![\w>:\\\\])(?:Carbon::)?now\(\)->format\([\'"]Y-m-d[\'"]\)/' => 'UTC date: use AgencyTime::today()',
        '/(?<![\w$>:])date\([\'"]Y-m-d[\'"]\)/' => 'server date: use AgencyTime::today()',
        '/(?<![\w>:\\\\])(?:Carbon::)?now\(\)->(?:startOf|endOf)(?:Day|Week|Month)\(/' => 'UTC boundary: use AgencyTime::boundaryUtc() / todayDate()',
        '/(?<![\w>:\\\\])(?:Carbon::)?now\(\)->(?:sub|add)\w*\([^()]*\)->(?:toDateString|startOfDay|endOfDay)\(/' => 'UTC date: use AgencyTime::todayDate()->…',
        '/\b(?:CURDATE|CURRENT_DATE|UTC_DATE)\b|[\'"(\s,=<>]NOW\(\)/' => 'SQL clock (server MST): bind a PHP value from AgencyTime',
        '/(?<![\w>:\\\\$])(?:Carbon::)?now\(\)->format\([\'"][^\'"]*[gGhH]:i/' => 'UTC clock time shown to people: use AgencyTime::now()->format()',
    ];

    public function handle(): int
    {
        $root = app_path();
        $hits = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $rel = ltrim(str_replace([$root, '\\'], ['', '/'], $file->getPathname()), '/');
            $allowed = in_array($rel, self::ALLOW, true);
            if ($allowed && ! $this->option('all')) {
                continue;
            }
            $inBlock = false;
            foreach (file($file->getPathname()) ?: [] as $n => $line) {
                // Comments describe the bug; they are not the bug. Block comments are tracked
                // across lines (their middle lines are plain prose); '/*' counts only at a token
                // start, so a MIME string like 'image/*' does not open one.
                if ($inBlock) {
                    if (str_contains($line, '*/')) {
                        $inBlock = false;
                    }
                    continue;
                }
                if (preg_match('#(?:^|\s)/\*#', $line, $m, PREG_OFFSET_CAPTURE)) {
                    $at = $m[0][1];
                    if (! str_contains(substr($line, $at), '*/')) {
                        $inBlock = true;
                    }
                    $line = substr($line, 0, $at);
                }
                $t = ltrim($line);
                if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '#')) {
                    continue;
                }
                if (stripos($line, 'tz-ok') !== false) {
                    continue;
                }
                foreach (self::PATTERNS as $re => $why) {
                    if (preg_match($re, $line)) {
                        $hits++;
                        $this->line(sprintf('%s:%d  %s', $rel, $n + 1, $why));
                        $this->line('    ' . trim(mb_substr($line, 0, 160)));
                        break;
                    }
                }
            }
        }
        if ($hits) {
            $this->error($hits . ' place(s) compute "today" outside the agency timezone.');

            return self::FAILURE;
        }
        $this->info('No UTC/server "today" found.');

        return self::SUCCESS;
    }
}
