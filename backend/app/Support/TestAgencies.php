<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Test / demo agencies (2026-10-30): agencies.settings.demo = true (Test Agency, Buckeye Kids Demo, Brightwood).
 * Platform-wide business numbers (Platform overview: totals, MRR, growth, churn, usage, email) leave them out,
 * so the figures describe real customers only. Nothing else changes for these agencies - they still work normally.
 */
final class TestAgencies
{
    private static ?array $ids = null;
    private static ?array $realIds = null;
    private static ?array $testUsers = null;

    public static function isTest(?object $agency): bool
    {
        if (! $agency) return false;
        $s = is_string($agency->settings ?? null) ? (json_decode($agency->settings, true) ?: []) : (array) ($agency->settings ?? []);
        return ($s['demo'] ?? false) === true;
    }

    /** Ids of every test agency (deleted ones included, so their history is excluded too). */
    public static function ids(): array
    {
        if (self::$ids === null) {
            self::$ids = DB::table('agencies')->get(['id', 'settings'])->filter(fn ($a) => self::isTest($a))->pluck('id')->map(fn ($i) => (int) $i)->values()->all();
        }
        return self::$ids;
    }

    /** Ids of the real (non-test) agencies, deleted ones included. */
    public static function realIds(): array
    {
        if (self::$realIds === null) {
            self::$realIds = DB::table('agencies')->whereNotIn('id', self::ids() ?: [0])->pluck('id')->map(fn ($i) => (int) $i)->all();
        }
        return self::$realIds;
    }

    /** Real centres' ids. */
    public static function realCentreIds(): array
    {
        return DB::table('centres')->whereIn('agency_id', self::realIds() ?: [0])->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /**
     * People who belong ONLY to test agencies (staff roles or guardians there, and nothing real, and not a platform admin).
     * Someone who also works at or has a child in a real agency counts as real.
     */
    public static function testOnlyUserIds(): array
    {
        if (self::$testUsers !== null) return self::$testUsers;
        $test = self::ids();
        if (! $test) return self::$testUsers = [];
        $inTest = DB::table('role_assignments')->whereIn('agency_id', $test)->pluck('user_id')
            ->merge(DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
                ->whereIn('c.agency_id', $test)->pluck('g.user_id'))
            ->filter()->map(fn ($i) => (int) $i)->unique();
        $real = self::realIds() ?: [0];
        $keep = DB::table('role_assignments')->where(fn ($q) => $q->whereIn('agency_id', $real)->orWhere('role', 'platform_admin'))->pluck('user_id')
            ->merge(DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
                ->whereIn('c.agency_id', $real)->pluck('g.user_id'))
            ->filter()->map(fn ($i) => (int) $i)->unique()->flip();
        return self::$testUsers = $inTest->reject(fn ($u) => isset($keep[$u]))->values()->all();
    }
}
