# Two gaps the real template exposed.
#
# 1. `?:` was never stripped. The regex demanded `??` or `??:`, so `$p->provider_name ?: '—'`
#    fell through as untranslatable and the recipient, type and status lines vanished. It is
#    also stripped INSIDE the peel loop now, because ucfirst($x ?: 'provider') only reveals
#    its `?:` after the ucfirst comes off.
#
# 2. @if(((float) $p->ot_hours) > 0) was never converted. The directive rules expected a
#    field name already in braces, but a Blade condition holds raw PHP — nothing had
#    rewritten it. Its argument now goes through the same resolver as everything else, which
#    left "$p->ot_hours) > 0)" as visible debris in the output.
import io, os, sys
os.chdir(sys.argv[1])
CRLF, LF = chr(13)+chr(10), chr(10)
P = 'app/Support/DocumentTemplate.php'
s = io.open(P, encoding='utf-8', newline='').read()

def rep(old, new, why):
    global s
    o, n = old, new
    if o not in s: o, n = old.replace(LF,CRLF), new.replace(LF,CRLF)
    assert o in s and s.count(o)==1, 'anchor: ' + why
    s = s.replace(o,n,1); print('  ' + why)

# 1. `?:` handling — both forms, and inside the peel loop.
rep("""        // `X ?: 'fallback'` and `X ?? 'fallback'` — the fallback is presentation, not data.
        $e = (string) preg_replace('/\s*\?\??:?\s*(\'[^\']*\'|"[^"]*")\s*$/', '', $e);
        $e = (string) preg_replace('/\s*\?:\s*\([^)]*\)\s*$/', '', $e);""",
    """        // `X ?: 'fallback'`, `X ?? 'fallback'`, `X ?: ($y ?: 'z')` — a fallback is
        // presentation, not data, and the field alone is what the template needs.
        $e = self::stripFallback($e);""",
    'fallback stripping moved to a helper')

rep("""            $e = (string) preg_replace('/^optional\((.*)\)$/s', '$1', $e);
            $e = trim($e);""",
    """            $e = (string) preg_replace('/^optional\((.*)\)$/s', '$1', $e);
            // Again after peeling: ucfirst($x ?: 'provider') only shows its ?: once the
            // ucfirst has come off.
            $e = self::stripFallback(trim($e));""",
    'fallbacks stripped after each peel')

rep("""    /**
     * Translate a Blade template into the safe subset.""",
    """    /** Remove a trailing `?:` / `??` default, however it is written. */
    private static function stripFallback(string $e): string
    {
        $prev = null;
        $guard = 0;
        while ($prev !== $e && $guard++ < 6) {
            $prev = $e;
            $e = (string) preg_replace('/\s*(?:\?\?|\?:)\s*(?:\'[^\']*\'|"[^"]*"|\([^()]*\)|[a-zA-Z0-9_\\x80-\\xff\\-]+)\s*$/u', '', trim($e));
        }

        return trim($e);
    }

    /**
     * Translate a Blade template into the safe subset.""",
    'stripFallback helper')

# 2. Blade conditions carry raw PHP — resolve them through the same resolver.
rep("""        // Control flow we can express, now that the expressions inside are field names.
        $s = (string) preg_replace('/@if\s*\(\s*\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}\s*(?:>\s*0\s*)?\)/', '{{#if $1}}', $s);""",
    """        // A Blade condition holds raw PHP, not a field name — nothing above has touched
        // it. Its argument goes through the same resolver, so @if(((float) $p->ot_hours) > 0)
        // becomes {{#if ot_hours}} instead of leaving "$p->ot_hours) > 0)" in the output.
        $s = (string) preg_replace_callback('/@if\s*\((.+?)\)\s*$/m', function ($m) use ($map, $derived, &$notes) {
            $inner = (string) preg_replace('/^\s*\(*\s*|\s*\)*\s*(?:>|!==?|>=)\s*(?:0|null|\'\')\s*$/', '', trim($m[1]));
            $var = self::resolveExpression($inner, $map, $derived);
            if ($var !== null) {
                return '{{#if ' . $var . '}}';
            }
            $notes[] = 'Could not translate the condition @if(' . trim($m[1]) . ') — the block is always shown.';

            return '';
        }, $s);
        $s = (string) preg_replace('/@if\s*\(\s*\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}\s*(?:>\s*0\s*)?\)/', '{{#if $1}}', $s);""",
    'Blade conditions resolved through the resolver')

io.open(P,'w',encoding='utf-8',newline='').write(s)
