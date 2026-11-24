<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * KiddieTrac the company (2026-11-04, Anthony: "invoices should show our business name KiddieTrac Inc (wire this up
 * somewhere that is reusable throughout the portal - full sweep").
 *
 * ONE place for who KiddieTrac is on paper: the legal name on invoices, receipts, proposals and every copyright
 * line, plus the address and phone the invoices already carry (invoice.issuer.*). The name is platform_settings
 * 'company.legal_name'; the portal's copy is KT.COMPANY in branding-loader.js (keep the two the same).
 * The brand in sentences ("your KiddieTrac services") stays KiddieTrac - this is the business name only.
 */
final class Company
{
    public const DEFAULT_LEGAL_NAME = 'KiddieTrac Inc';

    private static function setting(string $key, ?string $default = null): ?string
    {
        try {
            $v = Cache::remember('kt.company.' . $key, 300, fn () => DB::table('platform_settings')->where('key', $key)->value('value'));
            $v = trim((string) $v);
            return $v !== '' ? $v : $default;
        } catch (Throwable $e) {
            return $default;
        }
    }

    /** "KiddieTrac Inc" */
    public static function legalName(): string
    {
        return (string) self::setting('company.legal_name', self::DEFAULT_LEGAL_NAME);
    }

    /** "© 2026 KiddieTrac Inc. All Rights Reserved." (plain text; the year is the current one) */
    public static function copyright(): string
    {
        return "\u{00A9} " . date('Y') . ' ' . rtrim(self::legalName(), '.') . '. All Rights Reserved.';
    }

    /** The registered address, one line: "246372 Hockley Road, Mono, Ontario L9W 6K4, Canada" */
    public static function addressLine(): string
    {
        $a = (string) self::setting('invoice.issuer.address', '246372 Hockley Road, Mono ON L9W 6K4');
        return implode(', ', array_filter(array_map('trim', preg_split('/\R/', $a))));
    }

    public static function phone(): string
    {
        return (string) self::setting('invoice.issuer.phone', '1-855-400-9996');
    }
}
