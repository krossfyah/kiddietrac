<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * `php artisan lint:closures` — a closure that reads a variable it never imported.
 *
 * PHP closures do not inherit scope. Miss the `use (...)` and the variable is simply
 * undefined: no parse error, no warning at deploy, a 500 the first time a real person
 * reaches that line. Arrow functions (`fn () =>`) capture automatically, which is why
 * this only ever bites the long-hand ones — and why it survives review, because the
 * line beside it usually IS an arrow fn and looks identical in spirit.
 *
 * Three of these reached production on 2026-09-10 alone:
 *
 *   ReportsController  array_map(function ($r) { … $tz … })          Staff Hours 500'd
 *                      on every row it had to format; empty reports looked fine because
 *                      array_map never calls the callback for an empty set.
 *   WalkController     whereNotExists(function ($q) { … $walkFrom … }) every call to
 *                      the walk geofence died; the outer ->where() read the same two
 *                      variables and worked, so it read as a data problem.
 *
 * The tokenizer does the work rather than a regex, because the question is genuinely
 * structural: which variables does this closure's BODY read, which does it declare, and
 * is the name one the enclosing function had? Regex cannot see a brace.
 *
 *   php artisan lint:closures                 app/, quiet when clean
 *   php artisan lint:closures --path=app/Http
 *
 * Exit 1 on any finding, so it can gate a deploy.
 */
final class ClosureScopeCheck extends Command
{
    protected $signature = 'lint:closures
        {--path=app : directory to scan, relative to the project root}';

    protected $description = 'Find closures that read a variable they never imported with use()';

    /** Names that are always available and are never the bug. */
    private const ALWAYS = ['this', 'GLOBALS', '_SERVER', '_GET', '_POST', '_FILES',
        '_COOKIE', '_SESSION', '_REQUEST', '_ENV', 'http_response_header', 'argv', 'argc'];

    public function handle(): int
    {
        $root = base_path((string) $this->option('path'));
        if (! is_dir($root)) {
            $this->error("Not a directory: {$root}");

            return self::FAILURE;
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $findings = [];
        $scanned = 0;

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $scanned++;
            foreach ($this->scan($file->getPathname()) as $f) {
                $findings[] = $f;
            }
        }

        $this->line("Scanned {$scanned} files under " . $this->option('path'));

        if (! $findings) {
            $this->info('No closure reads a variable it did not import.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error(count($findings) . ' closure(s) read a variable they never imported:');
        foreach ($findings as $f) {
            $rel = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $f['file']);
            $this->error("  {$rel}:{$f['line']}  missing use(" . implode(', ', array_map(fn ($v) => '$' . $v, $f['missing'])) . ')');
            $this->line('      ' . trim($f['snippet']));
        }
        $this->newLine();
        $this->error('PHP closures do not inherit scope. Add them to use(...), or use fn() which captures automatically.');

        return self::FAILURE;
    }

    /** @return array<int, array{file:string,line:int,missing:string[],snippet:string}> */
    private function scan(string $path): array
    {
        $src = @file_get_contents($path);
        if ($src === false) {
            return [];
        }

        $tokens = @token_get_all($src);
        if (! $tokens) {
            return [];
        }

        $lines = explode("\n", $src);
        $out = [];
        $n = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            // A closure is `function (` — a named function has a name token between.
            $j = $this->nextMeaningful($tokens, $i + 1);
            if ($j === null) {
                continue;
            }
            // `function &(` is still a closure.
            if ($tokens[$j] === '&') {
                $j = $this->nextMeaningful($tokens, $j + 1);
            }
            if ($j === null || $tokens[$j] !== '(') {
                continue;   // named function or method
            }

            $line = $tokens[$i][2];

            // ── parameters ──
            $close = $this->matchParen($tokens, $j);
            if ($close === null) {
                continue;
            }
            $declared = $this->varsBetween($tokens, $j, $close);

            // ── use (...) ──
            $k = $this->nextMeaningful($tokens, $close + 1);
            if ($k !== null && is_array($tokens[$k]) && $tokens[$k][0] === T_USE) {
                $useOpen = $this->nextMeaningful($tokens, $k + 1);
                if ($useOpen !== null && $tokens[$useOpen] === '(') {
                    $useClose = $this->matchParen($tokens, $useOpen);
                    if ($useClose !== null) {
                        $declared = array_merge($declared, $this->varsBetween($tokens, $useOpen, $useClose));
                        $k = $this->nextMeaningful($tokens, $useClose + 1);
                    }
                }
            }

            // Skip a return type on the way to the body.
            while ($k !== null && $tokens[$k] !== '{' && $tokens[$k] !== ';') {
                $k = $this->nextMeaningful($tokens, $k + 1);
            }
            if ($k === null || $tokens[$k] !== '{') {
                continue;   // abstract/interface oddity
            }

            $bodyEnd = $this->matchBrace($tokens, $k);
            if ($bodyEnd === null) {
                continue;
            }

            /* Variables the body READS, minus the ones it declares itself. An
               assignment before first use makes it local, and a nested closure's own
               params are its business, not this one's. */
            [$read, $assigned] = $this->bodyVars($tokens, $k, $bodyEnd);

            $missing = array_values(array_diff(
                $read,
                $declared,
                $assigned,
                self::ALWAYS
            ));

            if (! $missing) {
                continue;
            }

            /* THE TEST THAT MAKES IT A REAL FINDING: the name exists in the enclosing
               scope. A variable that appears nowhere else is a different bug (a typo,
               or dead code), and flagging it here would drown the signal. */
            $enclosing = $this->enclosingVars($tokens, $i);
            $missing = array_values(array_intersect($missing, $enclosing));

            if ($missing) {
                $out[] = [
                    'file' => $path,
                    'line' => $line,
                    'missing' => $missing,
                    'snippet' => $lines[$line - 1] ?? '',
                ];
            }
        }

        return $out;
    }

    /**
     * Variables this closure's OWN body reads, and the ones it declares for itself.
     *
     * The first version counted everything between the braces and was useless: every
     * finding was a nested closure's parameter or a `catch (\Throwable $e)`. Four things
     * declare a name without an `=` sign, and all four have to be understood or the
     * report is noise — which gets it muted, which is worse than not having it:
     *
     *   · a NESTED function/fn — its params and use-list belong to IT. Skipped whole.
     *   · catch (\Throwable $e)
     *   · foreach (… as $k => $v)
     *   · [$a, $b] = …  and  list($a, $b) = …
     */
    private function bodyVars(array $tokens, int $from, int $to): array
    {
        $read = [];
        $assigned = [];

        for ($i = $from + 1; $i < $to; $i++) {
            $t = $tokens[$i];

            // ── a nested closure is its own scope: skip it entirely ──
            if (is_array($t) && in_array($t[0], [T_FUNCTION, T_FN], true)) {
                $brace = $i;
                $limit = min($to, $i + 4000);
                while ($brace < $limit && $tokens[$brace] !== '{'
                       && $tokens[$brace] !== '=>' && ! (is_array($tokens[$brace]) && $tokens[$brace][0] === T_DOUBLE_ARROW)) {
                    $brace++;
                }
                if ($brace < $limit && $tokens[$brace] === '{') {
                    $end = $this->matchBrace($tokens, $brace);
                    $i = ($end !== null && $end <= $to) ? $end : $i;
                    continue;
                }
                /* An arrow fn has no braces — its body runs to the comma or paren that
                   closes the call. Conservative: skip to the end of the enclosing
                   expression so its params are never read as this closure's. */
                $depth = 0;
                for ($j = $brace; $j < $to; $j++) {
                    if ($tokens[$j] === '(' || $tokens[$j] === '[') { $depth++; }
                    elseif ($tokens[$j] === ')' || $tokens[$j] === ']') {
                        if ($depth === 0) { break; }
                        $depth--;
                    } elseif ($tokens[$j] === ',' && $depth === 0) { break; }
                    elseif ($tokens[$j] === ';' && $depth === 0) { break; }
                }
                $i = $j;
                continue;
            }

            // ── catch (\Throwable $e) declares $e ──
            if (is_array($t) && $t[0] === T_CATCH) {
                $open = $this->nextMeaningful($tokens, $i + 1);
                if ($open !== null && $tokens[$open] === '(') {
                    $close = $this->matchParen($tokens, $open);
                    if ($close !== null) {
                        $assigned = array_merge($assigned, $this->varsBetween($tokens, $open, $close));
                        $i = $close;
                        continue;
                    }
                }
            }

            // ── foreach (… as $k => $v) declares the loop variables ──
            if (is_array($t) && $t[0] === T_FOREACH) {
                $open = $this->nextMeaningful($tokens, $i + 1);
                if ($open !== null && $tokens[$open] === '(') {
                    $close = $this->matchParen($tokens, $open);
                    if ($close !== null) {
                        $asAt = null;
                        for ($j = $open; $j < $close; $j++) {
                            if (is_array($tokens[$j]) && $tokens[$j][0] === T_AS) { $asAt = $j; break; }
                        }
                        if ($asAt !== null) {
                            // Before `as` is READ (the subject); after it is DECLARED.
                            $read = array_merge($read, $this->varsBetween($tokens, $open, $asAt));
                            $assigned = array_merge($assigned, $this->varsBetween($tokens, $asAt, $close));
                        }
                        $i = $close;
                        continue;
                    }
                }
            }

            if (! is_array($t) || $t[0] !== T_VARIABLE) {
                continue;
            }

            /* `AgencyMailer::$lastAgencyId` is a STATIC PROPERTY, not a local — the
               tokenizer reports it as T_VARIABLE all the same, and reading it needs no
               import. Reported once as a missing use() before this check existed. */
            $prev = $this->prevMeaningful($tokens, $i - 1);
            if ($prev !== null && is_array($tokens[$prev]) && $tokens[$prev][0] === T_DOUBLE_COLON) {
                continue;
            }

            $name = ltrim($t[1], '$');
            $next = $this->nextMeaningful($tokens, $i + 1);

            $plainAssign = $next !== null && $tokens[$next] === '=';
            $compound = $next !== null && is_array($tokens[$next]) && in_array($tokens[$next][0],
                [T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_COALESCE_EQUAL], true);

            if ($plainAssign) {
                $assigned[] = $name;      // local from here on
            } elseif ($compound) {
                $read[] = $name;          // compound assignment reads first
            } else {
                $read[] = $name;
            }
        }

        /* [$a, $b] = …  and list($a, $b) = … — destructuring declares without an `=`
           directly after each name. Cheap textual pass over the same range. */
        $slice = '';
        for ($i = $from; $i <= $to; $i++) {
            $slice .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        }
        if (preg_match_all('/(?:\[|list\s*\()([^\]\)]*)(?:\]|\))\s*=(?!=)/', $slice, $m)) {
            foreach ($m[1] as $group) {
                if (preg_match_all('/\$(\w+)/', $group, $vm)) {
                    $assigned = array_merge($assigned, $vm[1]);
                }
            }
        }

        return [array_values(array_unique($read)), array_values(array_unique($assigned))];
    }

    /** Every variable named in the function that CONTAINS this closure. */
    private function enclosingVars(array $tokens, int $closureAt): array
    {
        // Walk back to the nearest enclosing `function` that is a method/named function.
        $depth = 0;
        $out = [];
        for ($i = $closureAt - 1; $i >= 0; $i--) {
            $t = $tokens[$i];
            if ($t === '}') {
                $depth++;
            } elseif ($t === '{') {
                if ($depth === 0) {
                    // opening brace of the enclosing block — collect from here forward
                    $end = $this->matchBrace($tokens, $i);
                    if ($end !== null) {
                        for ($j = $i; $j < $end; $j++) {
                            if (is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE) {
                                $out[] = ltrim($tokens[$j][1], '$');
                            }
                        }
                    }
                    break;
                }
                $depth--;
            }
        }

        return array_values(array_unique($out));
    }

    private function varsBetween(array $tokens, int $from, int $to): array
    {
        $out = [];
        for ($i = $from; $i <= $to; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_VARIABLE) {
                $out[] = ltrim($tokens[$i][1], '$');
            }
        }

        return array_values(array_unique($out));
    }

    private function prevMeaningful(array $tokens, int $i): ?int
    {
        for (; $i >= 0; $i--) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    private function nextMeaningful(array $tokens, int $i): ?int
    {
        $n = count($tokens);
        for (; $i < $n; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    private function matchParen(array $tokens, int $open): ?int
    {
        $depth = 0;
        $n = count($tokens);
        for ($i = $open; $i < $n; $i++) {
            if ($tokens[$i] === '(') {
                $depth++;
            } elseif ($tokens[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function matchBrace(array $tokens, int $open): ?int
    {
        $depth = 0;
        $n = count($tokens);
        for ($i = $open; $i < $n; $i++) {
            $t = $tokens[$i];
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($t === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
}
