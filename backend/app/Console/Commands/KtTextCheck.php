<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * RAW ESCAPE CODES IN WHAT PEOPLE READ (2026-10-09).
 *
 * "the year\u{2019}s clearest invitation" went out in 59 emails: PHP translates \u{...}
 * only in double-quoted strings, and StatHolidays wrote it in single quotes. Three
 * checks, so the next one is found at deploy time rather than in somebody's inbox:
 *
 *   1. the code: every single-quoted string or nowdoc under app/, config/, routes/,
 *      resources/ and database/ that contains a \u{...} escape (tokenised, so comments
 *      and double-quoted strings are not flagged);
 *   2. the net: emails App\Support\MailText had to repair (audit email.text_repaired);
 *   3. the inbox: sent emails whose body still shows \u{...}, \uXXXX or a doubled entity.
 *
 * Nightly, and after every deploy (DEPLOY-PROTOCOL). Exit 1 + email to the platform
 * admins when anything is found.
 */
class KtTextCheck extends Command
{
    protected $signature = 'kt:text-check {--hours=26 : how far back to read repairs and sent mail} {--no-mail : print only}';
    protected $description = 'Find raw escape codes in code strings and in recent emails.';

    public function handle(): int
    {
        $found = [];
        foreach (self::scanCode(base_path()) as $line) {
            $found['Strings in the code that will show a raw escape code'][] = ['line' => $line];
        }

        $since = now()->subHours(max(1, (int) $this->option('hours')));
        foreach (DB::table('audit_logs')->where('action', 'email.text_repaired')->where('created_at', '>=', $since)
            ->orderByDesc('id')->limit(50)->get(['created_at', 'payload']) as $a) {
            $p = json_decode((string) $a->payload, true) ?: [];
            $found['Emails the send-time guard had to repair (fix the sender)'][] =
                ['line' => substr((string) $a->created_at, 0, 16) . ' "' . ($p['subject'] ?? '') . '" — ' . ($p['summary'] ?? '')];
        }

        DB::table('email_logs')->where('created_at', '>=', $since)->where('status', 'sent')
            ->select(['id', 'subject', 'created_at', 'body_html'])->orderBy('id')
            ->chunk(300, function ($rows) use (&$found) {
                foreach ($rows as $r) {
                    $b = (string) $r->body_html . ' ' . $r->subject;
                    if (preg_match('/\\\\u\{[0-9A-Fa-f]{1,6}\}|\\\\u[0-9A-Fa-f]{4}|&amp;(#[0-9]{1,7}|#x[0-9A-Fa-f]{1,6}|[a-z]{2,8});/', $b, $m)) {
                        $found['Sent emails showing a raw code'][] = ['line' => "email #{$r->id} ({$r->created_at}) \"" . mb_substr((string) $r->subject, 0, 70) . "\" — {$m[0]}"];
                    }
                }
            });

        foreach ($found as $title => $rows) {
            $this->error($title . ' (' . count($rows) . ')');
            foreach ($rows as $r) $this->line('   ' . $r['line']);
        }
        if (! $found) {
            $this->info('Clean: no raw escape codes in the code or in recent email.');
            return self::SUCCESS;
        }
        if (! $this->option('no-mail')) {
            KtIntegrityCheck::alertPlatformAdmins('[KiddieTrac] Text check — raw escape codes found',
                'Text that shows an escape code (a backslash, u and a number) instead of the character. The send-time guard repairs emails, but each source below should be fixed.', $found);
        }

        return self::FAILURE;
    }

    /** @return array<int,string> "path:line  escape  string-start" */
    public static function scanCode(string $base): array
    {
        $out = [];
        foreach (['app', 'config', 'routes', 'resources', 'database'] as $root) {
            $dir = $base . '/' . $root;
            if (! is_dir($dir)) continue;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $p = $f->getPathname();
                if (! str_ends_with($p, '.php')) continue;   // .php.bak-* copies are not loaded
                $src = (string) @file_get_contents($p);
                if (strpos($src, '\\u') === false) continue;
                $nowdoc = false;
                foreach (token_get_all($src) as $t) {
                    if (! is_array($t)) continue;
                    [$id, $text, $line] = $t;
                    if ($id === T_START_HEREDOC) { $nowdoc = str_contains($text, "'"); continue; }
                    if ($id === T_END_HEREDOC) { $nowdoc = false; continue; }
                    $literal = ($id === T_CONSTANT_ENCAPSED_STRING && $text[0] === "'") || ($id === T_ENCAPSED_AND_WHITESPACE && $nowdoc);
                    if ($literal && preg_match('/\\\\u\{[0-9A-Fa-f]{1,6}\}/', $text, $m)) {
                        $out[] = substr($p, strlen($base) + 1) . ':' . $line . '  ' . $m[0] . '  ' . mb_substr(preg_replace('/\s+/', ' ', $text), 0, 80);
                    }
                }
            }
        }
        return $out;
    }
}
