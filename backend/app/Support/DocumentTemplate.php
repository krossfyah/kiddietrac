<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Printable document templates: storage, a safe renderer, and a Blade importer.
 *
 * THE RENDERER EXECUTES NOTHING. It understands four things and no more:
 *
 *   {{ name }}                 a value, HTML-escaped
 *   {{{ name }}}               a value, raw (for pre-built HTML fragments only)
 *   {{#if name}} … {{/if}}     shown when the value is non-empty; {{#else}} supported
 *   {{#each rows}} … {{/each}} repeated per row, with {{ this.field }} inside
 *
 * That is deliberate. A Blade file is PHP, so rendering an uploaded one would give anybody
 * who can reach the import screen code execution on the server. The importer TRANSLATES
 * Blade into this subset and reports what it could not translate, rather than passing
 * anything through to an evaluator.
 */
class DocumentTemplate
{
    /** The document kinds a template can be written for, and what each one is given. */
    public const KINDS = [
        'payslip' => [
            'label' => 'Payslip',
            'vars' => [
                'agency_name', 'agency_logo', 'doc_title', 'doc_number',
                'payee_name', 'payee_role', 'recipient_type', 'period', 'period_start', 'period_end',
                'status', 'hours', 'rate', 'regular_amount',
                'ot_hours', 'ot_mult', 'ot_amount',
                'vacation', 'gross', 'gross_with_vacation',
                'cpp', 'ei', 'income_tax', 'other_deductions', 'net',
                'notes', 'generated_at',
            ],
        ],
        'invoice' => [
            'label' => 'Invoice',
            'vars' => [
                'agency_name', 'agency_logo', 'doc_title', 'doc_number',
                'payee_name', 'payee_role', 'issued_at', 'due_at', 'period',
                'status', 'subtotal', 'tax_label', 'tax_rate', 'tax_amount', 'total',
                'notes', 'generated_at', 'lines',
            ],
        ],
    ];

    /** The active template for an agency, falling back to the platform default. */
    public static function active(?int $agencyId, string $kind): ?object
    {
        return DB::table('document_templates')->where('kind', $kind)->where('is_active', 1)
            ->where(function ($q) use ($agencyId) {
                $q->where('agency_id', $agencyId)->orWhereNull('agency_id');
            })
            // An agency's own template wins over the platform default.
            ->orderByRaw('agency_id IS NULL')
            ->first();
    }

    /**
     * Render a stored template against a flat set of values.
     *
     * Order matters: loops are resolved first so that the conditionals and values inside
     * each row see that row's fields, not the outer scope.
     */
    public static function render(string $body, array $data): string
    {
        $out = self::renderEach($body, $data);
        $out = self::renderIf($out, $data);

        return self::renderVars($out, $data);
    }

    private static function renderEach(string $tpl, array $data): string
    {
        return (string) preg_replace_callback(
            '/\{\{#each\s+([a-zA-Z0-9_.]+)\s*\}\}(.*?)\{\{\/each\}\}/s',
            function ($m) use ($data) {
                $rows = self::lookup($data, $m[1]);
                if (! is_array($rows)) {
                    return '';
                }
                $chunk = '';
                foreach ($rows as $row) {
                    $scope = is_array($row) ? $row : ['this' => $row];
                    // `this.field` inside a loop, plus the outer values for anything else.
                    $merged = $data;
                    foreach ($scope as $k => $v) {
                        $merged['this.' . $k] = $v;
                        $merged[$k] = $v;
                    }
                    $inner = self::renderIf($m[2], $merged);
                    $chunk .= self::renderVars($inner, $merged);
                }

                return $chunk;
            },
            $tpl
        );
    }

    private static function renderIf(string $tpl, array $data): string
    {
        // Innermost-first, so nested conditionals resolve correctly.
        $prev = null;
        $guard = 0;
        while ($prev !== $tpl && $guard++ < 20) {
            $prev = $tpl;
            $tpl = (string) preg_replace_callback(
                '/\{\{#if\s+([a-zA-Z0-9_.]+)\s*\}\}((?:(?!\{\{#if).)*?)\{\{\/if\}\}/s',
                function ($m) use ($data) {
                    $parts = preg_split('/\{\{#else\}\}/', $m[2], 2);
                    $truthy = self::truthy(self::lookup($data, $m[1]));

                    return $truthy ? ($parts[0] ?? '') : ($parts[1] ?? '');
                },
                $tpl
            );
        }

        return $tpl;
    }

    private static function renderVars(string $tpl, array $data): string
    {
        // Raw first — otherwise the escaped rule would swallow the inner braces.
        $tpl = (string) preg_replace_callback('/\{\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}\}/', function ($m) use ($data) {
            return (string) self::scalar(self::lookup($data, $m[1]));
        }, $tpl);

        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function ($m) use ($data) {
            return htmlspecialchars((string) self::scalar(self::lookup($data, $m[1])), ENT_QUOTES, 'UTF-8');
        }, $tpl);
    }

    private static function lookup(array $data, string $key)
    {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }
        // Dotted access for nested arrays, e.g. lines.0.description
        $cur = $data;
        foreach (explode('.', $key) as $part) {
            if (is_array($cur) && array_key_exists($part, $cur)) {
                $cur = $cur[$part];
            } else {
                return null;
            }
        }

        return $cur;
    }

    private static function truthy($v): bool
    {
        if (is_array($v)) {
            return $v !== [];
        }
        if (is_numeric($v)) {
            return (float) $v != 0.0;
        }

        return ! ($v === null || $v === '' || $v === false);
    }

    private static function scalar($v)
    {
        return is_scalar($v) || $v === null ? $v : '';
    }

    /**
    /** Strip a trailing `?:` or `??` default, however it is written. */
    private static function stripFallback(string $e): string
    {
        $prev = null;
        $guard = 0;
        while ($prev !== $e && $guard++ < 6) {
            $prev = $e;
            $e = (string) preg_replace(
                '/\s*(?:\?\?|\?:)\s*(?:\'[^\']*\'|"[^"]*"|\([^()]*\)|[^\s()]+)\s*$/u',
                '', trim($e));
        }

        return trim($e);
    }

    /**
     * Translate a Blade template into the safe subset.
     *
     * Each {{ ... }} expression is taken WHOLE, its formatting wrappers peeled off, and the
     * remainder matched against known fields. Arithmetic a template cannot perform — hours
     * times rate, gross plus vacation — resolves to the named total we already supply.
     * Anything left unresolved is removed and reported: nothing unknown reaches the
     * renderer, so an import can never smuggle in something executable.
     *
     * @param  array<string,string>  $map  Blade expression → template variable
     * @return array{body:string,notes:string[]}
     */
    public static function importFromBlade(string $blade, array $map = [], array $derived = []): array
    {
        $notes = [];
        $s = $blade;

        // Unambiguous code comes out first.
        foreach ([
            '/<\?php.*?\?>/s' => 'a raw PHP block',
            '/@php\b.*?@endphp/s' => 'an @php block',
        ] as $re => $what) {
            $s = (string) preg_replace_callback($re, function () use (&$notes, $what) {
                $notes[] = 'Removed ' . $what . ' — a template cannot run code. Supply the value as a field instead.';

                return '';
            }, $s);
        }

        // Layout components, whose attributes may themselves contain ">" inside quotes.
        $s = (string) preg_replace_callback(
            '/<\/?x-[a-zA-Z0-9._-]+(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>/s',
            function ($m) use (&$notes) {
                $notes[] = 'Removed the layout wrapper ' . preg_split('/\s/', ltrim($m[0], '<'))[0]
                    . ' — KiddieTrac supplies the page around your template.';

                return '';
            },
            $s
        );

        // Whole expressions.
        $s = (string) preg_replace_callback('/\{\{(.+?)\}\}/s', function ($m) use ($map, $derived, &$notes) {
            $var = self::resolveExpression(trim($m[1]), $map, $derived);
            if ($var !== null) {
                return '{{ ' . $var . ' }}';
            }
            $notes[] = 'Could not translate {{ ' . trim(preg_replace('/\s+/', ' ', $m[1]))
                . ' }} — add a field for it, then place it by name.';

            return '';
        }, $s);

        // Control flow we can express, now that the expressions inside are field names.
        // A Blade condition contains raw PHP, not a field name. Nothing above rewrites
        // it, so the old rule never matched and left "$p->ot_hours) > 0)" visible in the
        // output. Its argument goes through the same resolver as every other expression.
        $s = (string) preg_replace_callback('/@if\s*\((?:[^()]++|\((?:[^()]++|\([^()]*\))*\))*\)/', function ($m) use ($map, $derived, &$notes) {
            // $m[0] is the whole directive; take what is inside its outer parens.
            $inner = trim(substr(trim($m[0]), strpos(trim($m[0]), '(') + 1, -1));
            $inner = (string) preg_replace('/\s*(?:>|>=|!==?)\s*(?:0|null)\s*$/', '', $inner);
            // Only balanced wrapping parens come off — trimming the characters
            // turned "((float) $x" into "float) $x", which resolves to nothing.
            while (str_starts_with($inner, '(') && str_ends_with($inner, ')')) {
                $trial = trim(substr($inner, 1, -1));
                if (substr_count($trial, '(') !== substr_count($trial, ')')) { break; }
                $inner = $trial;
            }
            $var = self::resolveExpression($inner, $map, $derived);
            if ($var !== null) {
                return '{{#if ' . $var . '}}';
            }
            $notes[] = 'Could not translate the condition @if(' . trim($m[1]) . ') — that block now always shows.';

            return '';
        }, $s);
        $s = (string) preg_replace('/@elseif\b[^\n]*/', '', $s);
        $s = (string) preg_replace('/@endif\b/', '{{/if}}', $s);
        $s = (string) preg_replace('/@else\b/', '{{#else}}', $s);
        $s = (string) preg_replace('/@(?:foreach|forelse)\s*\(\s*\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}\s+as\s+[^)]*\)/', '{{#each $1}}', $s);
        $s = (string) preg_replace('/@(?:endforeach|endforelse)\b/', '{{/each}}', $s);

        // Anything still starting with @ is a directive we do not implement.
        $s = (string) preg_replace_callback('/@[a-zA-Z]+\s*(\([^)]*\))?/', function ($m) use (&$notes) {
            $notes[] = 'Removed unsupported directive ' . trim($m[0]) . '.';

            return '';
        }, $s);

        // Tidy the blank lines left behind by everything that was removed.
        $s = (string) preg_replace('/\n{3,}/', "\n\n", $s);

        return ['body' => trim($s), 'notes' => array_values(array_unique($notes))];
    }

    /**
     * One Blade expression → one field name, or null when it cannot be expressed.
     *
     * Wrappers are peeled rather than matched exactly, because a print template wraps the
     * same value differently in different places — number_format here, ucfirst there — and
     * all of them mean "show this field".
     */
    private static function resolveExpression(string $expr, array $map, array $derived)
    {
        $e = trim($expr);

        // A trailing default is presentation, not data. The old rule demanded `??`, so a
        // plain `?:` — which is what a real template uses — fell through as untranslatable
        // and took the recipient, type and status lines with it.
        $e = self::stripFallback($e);

        // Peel formatting calls repeatedly: number_format(X, 2) → X, ucfirst(X) → X …
        $prev = null;
        $guard = 0;
        while ($prev !== $e && $guard++ < 8) {
            $prev = $e;
            $e = (string) preg_replace(
                '/^\s*(?:number_format|ucfirst|ucwords|strtoupper|strtolower|trim|e|rtrim|ltrim|nl2br)\s*\((.*)\)\s*$/s',
                '$1', $e);
            // Drop trailing arguments of the call we just peeled, e.g. "X, 2" or "X, '0'".
            $e = (string) preg_replace('/\s*,\s*(?:\d+|\'[^\']*\'|"[^"]*")\s*$/', '', $e);
            $e = trim($e);
            $e = (string) preg_replace('/^\(\s*(?:float|int|string|bool)\s*\)\s*/', '', $e);
            $e = (string) preg_replace('/^optional\((.*)\)$/s', '$1', $e);
            // Again after peeling: ucfirst($x ?: 'provider') only reveals its ?: once the
            // ucfirst has come off.
            $e = self::stripFallback(trim($e));
        }

        // Arithmetic a template cannot do — matched against the named totals we supply.
        $norm = preg_replace('/\s+|\(float\)|\(int\)/', '', $e);
        foreach ($derived as $pattern => $field) {
            if ($norm === preg_replace('/\s+|\(float\)|\(int\)/', '', $pattern)) {
                return $field;
            }
        }

        // A plain field.
        if (isset($map[$e])) {
            return $map[$e];
        }
        // Already one of ours (a re-import of an exported template).
        if (preg_match('/^[a-zA-Z0-9_.]+$/', $e) && ! str_contains($e, '$')) {
            return $e;
        }

        return null;
    }
}
