<?php

declare(strict_types=1);

namespace App\Support\Payroll;

/**
 * KIDDIETRAC PAYROLL ENGINE, PHASE 1 (2026-11-27): payroll tax tables are DATA, not code.
 *
 * One JSON file per published edition under backend/resources/payroll/{country}/{year}/{effective}.json
 * (CA: CRA T4127 Payroll Deductions Formulas + Revenu Quebec TP-1015.F; US Phase 2: IRS Pub 15-T + state tables).
 * Each file is complete on its own and cites its edition and source URLs. A pay uses the newest edition whose
 * effective date is on or before the pay date, inside the pay date's tax year. No edition for that year = no
 * calculation (never a guess from another year): forYear() returns null and the caller must stop.
 */
final class TaxTables
{
    public const COUNTRIES = ['CA'];

    /** @var array<string, array> */
    private static array $cache = [];

    public static function dir(string $country): string
    {
        return resource_path('payroll/' . strtolower($country));
    }

    /** Every edition for a country, oldest first: [{version, effective, tax_year, edition, path}]. */
    public static function editions(string $country): array
    {
        $out = [];
        foreach (glob(self::dir($country) . '/*/*.json') ?: [] as $path) {
            if (! preg_match('#/(\d{4})/(\d{4}-\d{2}-\d{2})\.json$#', str_replace('\\', '/', $path), $m)) {
                continue;
            }
            $t = self::read($path);
            $out[] = ['version' => (string) ($t['version'] ?? ''), 'effective' => $m[2], 'tax_year' => (int) $m[1],
                'edition' => (string) ($t['edition'] ?? ''), 'quebec' => (string) ($t['quebec']['edition'] ?? ''), 'path' => $path];
        }
        usort($out, fn ($a, $b) => strcmp($a['effective'], $b['effective']));

        return $out;
    }

    /** The edition in force on a pay date (Y-m-d), or null when that tax year has no published table. */
    public static function forDate(string $country, string $payDate): ?array
    {
        $year = (int) substr($payDate, 0, 4);
        $pick = null;
        foreach (self::editions($country) as $e) {
            if ($e['tax_year'] === $year && $e['effective'] <= $payDate) {
                $pick = $e;
            }
        }

        return $pick ? self::read($pick['path']) : null;
    }

    /** Years that have at least one edition. @return int[] */
    public static function years(string $country): array
    {
        return array_values(array_unique(array_map(fn ($e) => $e['tax_year'], self::editions($country))));
    }

    /** Does a tax year have tables (federal + every province; Quebec income tax checked separately)? */
    public static function hasYear(string $country, int $year): bool
    {
        return in_array($year, self::years($country), true);
    }

    /** Does a tax year's newest edition carry the Quebec income tax block (TP-1015.F)? */
    public static function hasQuebec(int $year): bool
    {
        $t = self::forDate('CA', $year . '-12-31');

        return $t !== null && ! empty($t['quebec']['brackets']);
    }

    private static function read(string $path): array
    {
        if (! isset(self::$cache[$path])) {
            $j = json_decode((string) file_get_contents($path), true);
            if (! is_array($j)) {
                throw new \RuntimeException('Payroll tax table is not valid JSON: ' . basename(dirname($path)) . '/' . basename($path));
            }
            self::$cache[$path] = $j;
        }

        return self::$cache[$path];
    }
}
