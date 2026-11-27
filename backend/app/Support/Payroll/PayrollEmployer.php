<?php

declare(strict_types=1);

namespace App\Support\Payroll;

use Illuminate\Support\Facades\DB;

/**
 * KIDDIETRAC PAYROLL ENGINE, PHASE 2 (2026-11-27): the agency's payroll switch and employer details, kept at
 * agencies.settings.payroll_engine (its own key: nothing else rewrites it).
 *
 *   enabled          the HR settings switch (PayrollTax::engineOn reads it). OFF by default; while off every engine route
 *                    answers {enabled:false} and the existing gross-only payroll is untouched.
 *   acknowledged_at / acknowledged_by   the one-time "check with your accountant before your first live run" notice. The
 *                    switch cannot be turned on without it, and no run can be made until it is recorded.
 *   rp_number        CRA business number + payroll program account (123456789RP0001)
 *   rq_number        Revenu Quebec identification + file number (1234567890RS0001), if the agency pays anyone in Quebec
 *   remitter_type    regular | quarterly | accelerated_1 | accelerated_2 (CRA's remitter types; decides the PD7A due dates)
 *   wsib_rate        workers' compensation premium rate per $100 of insurable earnings: shown on the remittance report only,
 *                    never deducted (it is an employer cost) and never filed
 * KiddieTrac never moves money and never files: it produces the numbers; the agency remits them.
 */
final class PayrollEmployer
{
    public const REMITTERS = [
        'regular' => 'Regular (monthly): due the 15th of the next month',
        'quarterly' => 'Quarterly: due the 15th after each quarter',
        'accelerated_1' => 'Accelerated, threshold 1: twice a month',
        'accelerated_2' => 'Accelerated, threshold 2: up to four times a month',
    ];

    public const ACK_TEXT = 'Check with your accountant before your first live pay run. KiddieTrac works out deductions from the CRA and Revenu Québec formulas and tables, but you are responsible for what you pay, remit and file. Compare the first runs with your current payroll before relying on them.';

    public static function settings(int $agencyId): array
    {
        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];
        $p = is_array($s['payroll_engine'] ?? null) ? $s['payroll_engine'] : [];
        $type = (string) ($p['remitter_type'] ?? 'regular');

        return [
            'enabled' => ($p['enabled'] ?? false) === true,
            'acknowledged_at' => $p['acknowledged_at'] ?? null,
            'acknowledged_by' => isset($p['acknowledged_by']) ? (int) $p['acknowledged_by'] : null,
            'rp_number' => $p['rp_number'] ?? null,
            'rq_number' => $p['rq_number'] ?? null,
            'remitter_type' => isset(self::REMITTERS[$type]) ? $type : 'regular',
            'wsib_rate' => isset($p['wsib_rate']) && $p['wsib_rate'] !== null ? (float) $p['wsib_rate'] : null,
        ];
    }

    public static function acknowledged(int $agencyId): bool
    {
        return (bool) self::settings($agencyId)['acknowledged_at'];
    }

    /** Merge into agencies.settings.payroll_engine under a row lock (keys outside it are never touched). */
    public static function save(int $agencyId, array $in): array
    {
        DB::transaction(function () use ($agencyId, $in) {
            $row = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->first(['settings']);
            $all = json_decode((string) ($row->settings ?? ''), true);
            $all = is_array($all) ? $all : [];
            $cur = is_array($all['payroll_engine'] ?? null) ? $all['payroll_engine'] : [];
            $all['payroll_engine'] = array_replace($cur, $in);
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        });

        return self::settings($agencyId);
    }

    /** "123456789RP0001" (spaces / dashes allowed in, stored without). Null when it is not that shape. */
    public static function cleanRp(?string $v): ?string
    {
        $v = strtoupper(preg_replace('/[\s-]+/', '', (string) $v));

        return preg_match('/^\d{9}RP\d{4}$/', $v) ? $v : null;
    }

    /** "1234567890RS0001" (Quebec identification number + file number). */
    public static function cleanRq(?string $v): ?string
    {
        $v = strtoupper(preg_replace('/[\s-]+/', '', (string) $v));

        return preg_match('/^\d{10}(RS|TQ)\d{4}$/', $v) ? $v : null;
    }
}
