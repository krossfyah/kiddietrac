<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * KIDDIETRAC HR PHASE 4, BATCH 4 (2026-11-27): PAY + ROLE HISTORY WITH EFFECTIVE DATES.
 *
 * hr_pay_changes holds every change to a person's pay rate, pay type, job title, employment type and contracted
 * hours, with the day it takes effect, who asked for it and why. A change dated today or earlier applies at once; a
 * future one waits (status scheduled) and hr:file-tasks applies it on its day in the AGENCY's timezone. When a change
 * applies, `before` keeps what it replaced, so the history reads "from -> to" for ever. Applied rows are never edited;
 * a scheduled one can be cancelled. Edits made in the Edit file form are recorded too (source file_edit).
 *
 * Pay fields (pay_rate, pay_type) are shown only to callers who may see pay (HrController::canSeePay rule: managers
 * with the payroll feature). Nobody changes their own pay or role here.
 * Where things live: users.pay_rate / users.pay_type; hr_employee_files.job_title / employment_type / hours_per_week.
 */
final class HrPay
{
    public const PAY_FIELDS = ['pay_rate', 'pay_type'];

    public const FIELDS = ['pay_rate', 'pay_type', 'job_title', 'employment_type', 'hours_per_week'];

    /** The two values users.pay_type holds today (checked 2026-11-27). */
    public const PAY_TYPES = ['hourly' => 'Hourly', 'salary' => 'Salary'];

    /** What the person has now, for these fields. */
    public static function current(int $agencyId, int $userId): array
    {
        $u = DB::table('users')->where('id', $userId)->first(['pay_rate', 'pay_type']);
        $f = DB::table('hr_employee_files')->where('agency_id', $agencyId)->where('user_id', $userId)->first();

        return ['pay_rate' => $u && $u->pay_rate !== null ? (float) $u->pay_rate : null, 'pay_type' => $u->pay_type ?? null,
            'job_title' => $f->job_title ?? null, 'employment_type' => $f->employment_type ?? null,
            'hours_per_week' => $f && $f->hours_per_week !== null ? (float) $f->hours_per_week : null];
    }

    /** Apply one scheduled change (idempotent: an applied row is left alone). */
    public static function apply(object $row): bool
    {
        if ($row->status !== 'scheduled') return false;
        $aid = (int) $row->agency_id;
        $uid = (int) $row->user_id;
        $ch = array_intersect_key((array) (json_decode((string) $row->changes, true) ?: []), array_flip(self::FIELDS));

        return (bool) DB::transaction(function () use ($row, $aid, $uid, $ch) {
            $fresh = DB::table('hr_pay_changes')->where('id', $row->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->status !== 'scheduled') return false;
            $before = self::current($aid, $uid);
            $u = array_intersect_key($ch, array_flip(self::PAY_FIELDS));
            if ($u) DB::table('users')->where('id', $uid)->update($u + ['updated_at' => now()]);
            $f = array_diff_key($ch, array_flip(self::PAY_FIELDS));
            if ($f) {
                if (array_key_exists('employment_type', $f)) HrLeave::syncContractorFlag($uid, $before['employment_type'], $f['employment_type']);
                $q = DB::table('hr_employee_files')->where('agency_id', $aid)->where('user_id', $uid);
                if ($q->exists()) $q->update($f + ['updated_at' => now()]);
                else DB::table('hr_employee_files')->insert($f + ['agency_id' => $aid, 'user_id' => $uid, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('hr_pay_changes')->where('id', $row->id)->update(['status' => 'applied', 'applied_at' => now(),
                'before' => json_encode(array_intersect_key($before, $ch)), 'updated_at' => now()]);
            try {
                Audit::write(['user_id' => $row->created_by_id, 'agency_id' => $aid, 'action' => 'hr.pay_change_applied', 'entity_type' => 'user',
                    'entity_id' => $uid, 'payload' => json_encode(['change_id' => (int) $row->id, 'fields' => array_keys($ch), 'effective_on' => $row->effective_on])]);
            } catch (\Throwable $e) {
            }

            return true;
        });
    }

    /** Every scheduled change that is due today or earlier in its agency. Returns how many applied. */
    public static function applyDue(int $agencyId, bool $dry = false): int
    {
        $today = AgencyTime::today($agencyId);
        $rows = DB::table('hr_pay_changes')->where('agency_id', $agencyId)->where('status', 'scheduled')->where('effective_on', '<=', $today)
            ->orderBy('effective_on')->orderBy('id')->get();
        if ($dry) return $rows->count();
        $n = 0;
        foreach ($rows as $r) {
            if (self::apply($r)) $n++;
        }

        return $n;
    }

    /** Record an edit made in the Edit file form as an applied history row (only fields that really changed). */
    public static function recordEdit(int $agencyId, int $userId, array $before, array $after, ?int $by): void
    {
        $ch = [];
        $was = [];
        foreach (['job_title', 'employment_type'] as $k) {
            if (array_key_exists($k, $after) && (string) ($before[$k] ?? '') !== (string) ($after[$k] ?? '')) {
                $ch[$k] = $after[$k];
                $was[$k] = $before[$k] ?? null;
            }
        }
        if (! $ch) return;
        DB::table('hr_pay_changes')->insert(['agency_id' => $agencyId, 'user_id' => $userId, 'effective_on' => AgencyTime::today($agencyId),
            'changes' => json_encode($ch), 'before' => json_encode($was), 'reason' => 'Edited in the employee file', 'status' => 'applied',
            'source' => 'file_edit', 'created_by_id' => $by, 'applied_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** The history for a person, newest first. Pay fields are dropped (and pay-only rows hidden) when $pay is false. */
    public static function history(int $agencyId, int $userId, bool $pay): array
    {
        $rows = DB::table('hr_pay_changes')->where('agency_id', $agencyId)->where('user_id', $userId)
            ->orderByDesc('effective_on')->orderByDesc('id')->limit(200)->get();
        $names = DB::table('users')->whereIn('id', $rows->pluck('created_by_id')->merge($rows->pluck('cancelled_by_id'))->filter()->unique()->all() ?: [0])
            ->get(['id', 'first_name', 'last_name'])->mapWithKeys(fn ($u) => [(int) $u->id => trim($u->first_name . ' ' . $u->last_name)])->all();
        $out = [];
        foreach ($rows as $r) {
            $ch = (array) (json_decode((string) $r->changes, true) ?: []);
            $was = (array) (json_decode((string) $r->before, true) ?: []);
            if (! $pay) {
                $ch = array_diff_key($ch, array_flip(self::PAY_FIELDS));
                $was = array_diff_key($was, array_flip(self::PAY_FIELDS));
                if (! $ch) continue;
            }
            $out[] = ['id' => (int) $r->id, 'effective_on' => $r->effective_on, 'status' => $r->status, 'source' => $r->source,
                'changes' => $ch, 'before' => $was, 'reason' => $r->reason, 'by' => $names[(int) $r->created_by_id] ?? null,
                'applied_at' => $r->applied_at, 'cancelled_by' => $r->cancelled_by_id ? ($names[(int) $r->cancelled_by_id] ?? null) : null,
                'created_at' => $r->created_at];
        }

        return $out;
    }
}
