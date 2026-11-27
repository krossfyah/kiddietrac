<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * KIDDIETRAC HR PHASE 4, BATCH 4 (2026-11-27): THE LEAVING PACK.
 *
 * hr_leavings = one leaving: last day, the reason (Service Canada ROE reason codes, for the prep sheet), a checklist
 * (keys, devices, final pay, leave payout, letter, ROE, the account), and a leave-payout worksheet. It does NOT close
 * the account: that stays StaffOffboardController (rooms, punches, shifts, sign-in) and the checklist links to it and
 * ticks itself once the account is closed. Completing marks the HR file Terminated with the end date, books the leave
 * payout in the ledger (kind adjustment, source_key payout:<id>, so the balance reads zero) and hands their direct
 * reports to a new manager. UNDO (a mistaken termination) reverses exactly that - file Active, payout returned
 * (payout-undo:<id>), account reopened through AdminController::reactivateUser - and keeps the row as `undone`.
 * A RETURNING employee keeps the old leaving as history (rehired_on) and starts a new employment: new start date,
 * file Active, optionally balances back to zero (rehire:<id>).
 *
 * The ROE sheet is PREP ONLY: KiddieTrac never files with Service Canada (said on the screen and the sheet).
 * Leavers = HrLeaving::leaverIds: the agency's former staff (account closed, or HR file Terminated), never an outsider.
 */
final class HrLeaving
{
    /** Service Canada ROE block 16 reason codes (the main code; sub-codes are chosen in ROE Web). */
    public const REASONS = [
        'A' => 'Shortage of work / end of contract or season', 'B' => 'Strike or lockout', 'D' => 'Illness or injury',
        'E' => 'Quit', 'F' => 'Maternity', 'G' => 'Retirement', 'H' => 'Work-Sharing', 'J' => 'Apprentice training',
        'K' => 'Other', 'M' => 'Dismissal or suspension', 'N' => 'Leave of absence', 'P' => 'Parental',
        'Z' => 'Compassionate care / family caregiver',
    ];

    public const CHECKLIST = [
        'keys' => 'Keys, fobs and access cards returned',
        'devices' => 'Devices returned (tablet, phone, laptop)',
        'uniform' => 'Uniform and equipment returned',
        'final_pay' => 'Final pay settled (hours since the last payslip)',
        'payout' => 'Leave balance paid out or settled',
        'letter' => 'Leaving letter sent (HR > Letters)',
        'roe' => 'ROE filed with Service Canada (outside KiddieTrac)',
        'reports' => 'Their direct reports have a new manager',
        'access' => 'Account closed: rooms, shifts and sign-in (Off-board)',
        'exit_talk' => 'Exit conversation held (optional)',
    ];

    /** The agency's former staff: an account that was closed, or an HR file marked Terminated. Never an outsider. @return int[] */
    public static function leaverIds(int $agencyId, ?array $centreScope = null): array
    {
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $q = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')->whereIn('ra.role', HrLeave::STAFF_ROLES)
            ->where(fn ($w) => $w->where(fn ($a) => $a->where('ra.agency_id', $agencyId)->whereNull('ra.centre_id'))->orWhereIn('ra.centre_id', $centres ?: [0]));
        if ($centreScope !== null) $q->whereIn('ra.centre_id', array_values(array_intersect($centreScope, $centres)) ?: [0]);
        $placed = $q->distinct()->pluck('u.id')->map(fn ($v) => (int) $v)->all();
        if (! $placed) return [];
        $closed = DB::table('users')->whereIn('id', $placed)->where(fn ($w) => $w->whereNotNull('deleted_at')->orWhere('status', 'deactivated'))
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
        $terminated = DB::table('hr_employee_files')->where('agency_id', $agencyId)->where('status', 'terminated')->whereIn('user_id', $placed)
            ->pluck('user_id')->map(fn ($v) => (int) $v)->all();

        return array_values(array_diff(array_unique(array_merge($closed, $terminated)), AgencySignatories::outsiderIds() ?: [0]));
    }

    public static function open(int $agencyId, int $userId): ?object
    {
        return DB::table('hr_leavings')->where('agency_id', $agencyId)->where('user_id', $userId)->whereIn('status', ['open', 'completed'])
            ->whereNull('rehired_on')->orderByDesc('id')->first();
    }

    /** The checklist with auto-ticks for what KiddieTrac can see for itself. */
    public static function checklist(int $agencyId, int $userId, ?object $row): array
    {
        $saved = $row ? (array) (json_decode((string) $row->checklist, true) ?: []) : [];
        $closed = (bool) DB::table('users')->where('id', $userId)->where(fn ($w) => $w->whereNotNull('deleted_at')->orWhere('status', 'deactivated'))->exists();
        $reports = (int) DB::table('hr_employee_files')->where('agency_id', $agencyId)->where('manager_user_id', $userId)
            ->whereIn('user_id', HrLeave::staffIds($agencyId) ?: [0])->count();
        $out = [];
        foreach (self::CHECKLIST as $k => $label) {
            $s = (array) ($saved[$k] ?? []);
            $auto = $k === 'access' ? $closed : ($k === 'reports' ? $reports === 0 : null);
            $out[] = ['key' => $k, 'label' => $label, 'done' => $auto ?? (bool) ($s['done'] ?? false), 'auto' => $auto !== null,
                'note' => $s['note'] ?? null, 'by' => $s['by'] ?? null, 'at' => $s['at'] ?? null, 'count' => $k === 'reports' ? $reports : null];
        }

        return $out;
    }

    /**
     * The leave-payout worksheet: each paid leave type with a balance, valued at the current rate.
     * Hourly: hours x rate (days x hours per day). Salary: treated as an ANNUAL salary / 260 working days (shown so).
     * The admin can change each amount before completing; null values when the caller may not see pay.
     */
    public static function payout(int $agencyId, int $userId, bool $pay): array
    {
        $s = HrLeave::settings($agencyId);
        $hpd = (float) ($s['hours_per_day'] ?: 7.5);
        $u = DB::table('users')->where('id', $userId)->first(['pay_rate', 'pay_type']);
        $rate = $u && $u->pay_rate !== null ? (float) $u->pay_rate : null;
        $hourly = $rate === null ? null : (($u->pay_type ?? 'hourly') === 'salary' ? $rate / 260 / $hpd : $rate);
        $lines = [];
        foreach (HrLeave::summary($agencyId, $userId) as $t) {
            if (! $t['paid'] || ! $t['tracked'] || abs((float) $t['balance']) < 0.01) continue;
            $hours = $t['unit'] === 'hours' ? (float) $t['balance'] : (float) $t['balance'] * $hpd;
            $lines[] = ['leave_type_id' => $t['leave_type_id'], 'name' => $t['name'], 'unit' => $t['unit'], 'balance' => (float) $t['balance'],
                'hours' => round($hours, 2), 'value' => $pay && $hourly !== null ? round($hours * $hourly, 2) : null];
        }

        return ['lines' => $lines, 'hours_per_day' => $hpd, 'pay_type' => $pay ? ($u->pay_type ?? null) : null,
            'hourly_rate' => $pay && $hourly !== null ? round($hourly, 4) : null,
            'total' => $pay ? round(array_sum(array_map(fn ($l) => (float) $l['value'], $lines)), 2) : null];
    }

    /** ROE prep facts (never filed from here). $pay false = hours only, no earnings. */
    public static function roe(int $agencyId, int $userId, bool $pay): array
    {
        $u = DB::table('users')->where('id', $userId)->first(['id', 'first_name', 'last_name', 'hire_date', 'email']);
        $row = self::open($agencyId, $userId);
        $file = HrLeave::file($agencyId, $userId);
        $first = $row && $row->employment_start ? (string) $row->employment_start : ($u && $u->hire_date ? substr((string) $u->hire_date, 0, 10) : null);
        $last = $row && $row->last_day ? (string) $row->last_day : ($file->end_date ?? null);
        $docs = DB::table('payroll_documents')->where('agency_id', $agencyId)->where('user_id', $userId)->where('kind', 'payslip')
            ->whereIn('status', ['issued', 'paid'])->whereNotNull('period_end')->orderByDesc('period_end')->limit(53)->get();
        $periods = [];
        foreach ($docs as $d) {
            $hrs = $d->unit_label === 'hours' ? (float) $d->units : self::punchHours($userId, (string) $d->period_start, (string) $d->period_end);
            $periods[] = ['period_start' => $d->period_start ? substr((string) $d->period_start, 0, 10) : null, 'period_end' => substr((string) $d->period_end, 0, 10),
                'hours' => round($hrs, 2), 'hours_from' => $d->unit_label === 'hours' ? 'payslip' : 'timesheets',
                'gross' => $pay ? round((float) $d->gross, 2) : null, 'vacation_pay' => $pay ? round((float) $d->vacation_pay, 2) : null];
        }
        // No payslips on file: 53 weeks of hours from the timesheets, ending on the last day.
        $weeks = [];
        if (! $periods) {
            $end = Carbon::parse($last ?: AgencyTime::today($agencyId));
            for ($i = 0; $i < 53; $i++) {
                $to = $end->copy()->subWeeks($i);
                $from = $to->copy()->subDays(6);
                if ($first && $to->toDateString() < $first) break;
                $weeks[] = ['period_start' => $from->toDateString(), 'period_end' => $to->toDateString(), 'hours' => round(self::punchHours($userId, $from->toDateString(), $to->toDateString()), 2), 'hours_from' => 'timesheets', 'gross' => null, 'vacation_pay' => null];
            }
        }
        $rows = $periods ?: $weeks;
        $payout = $row && $row->payout ? (array) json_decode((string) $row->payout, true) : null;

        return ['employee' => ['name' => $u ? trim($u->first_name . ' ' . $u->last_name) : '', 'first_name' => $u->first_name ?? '', 'last_name' => $u->last_name ?? ''],
            'employer' => (string) DB::table('agencies')->where('id', $agencyId)->value('name'),
            'first_day_worked' => $first, 'last_day_paid' => $last, 'final_period_end' => $periods[0]['period_end'] ?? null,
            'pay_frequency' => $docs->first()->pay_frequency ?? null,
            'reason_code' => $row->reason_code ?? null, 'reason_label' => $row && $row->reason_code ? (self::REASONS[$row->reason_code] ?? null) : null,
            'periods' => $rows, 'source' => $periods ? 'payslips' : 'timesheets',
            'total_hours' => round(array_sum(array_column($rows, 'hours')), 2),
            'total_gross' => $pay && $periods ? round(array_sum(array_column($rows, 'gross')), 2) : null,
            'vacation_paid_out' => $pay && $payout ? ($payout['total'] ?? null) : null,
            'notice' => 'Preparation sheet only. KiddieTrac does not file Records of Employment: enter these figures in ROE Web (Service Canada) or your payroll provider, and check them against your payroll records. The SIN is on the employee file (agency admins only) and is not printed here.'];
    }

    /** Closed punches inside a date range (by clock-in day), in hours; the timesheet rules apply when the agency has them on. */
    public static function punchHours(int $userId, string $from, string $to): float
    {
        $p = DB::table('time_punches')->where('user_id', $userId)->whereNotNull('punched_out_at')
            ->where('punched_in_at', '>=', $from . ' 00:00:00')->where('punched_in_at', '<=', $to . ' 23:59:59')
            ->get(['id', 'user_id', 'centre_id', 'punched_in_at', 'punched_out_at']);
        if ($p->isEmpty()) return 0.0;
        $map = HrTime::paidMap($p);
        $min = 0;
        foreach ($p as $r) {
            $min += $map !== null && isset($map[$r->id]) ? (int) $map[$r->id] : max(0, (int) round((strtotime((string) $r->punched_out_at) - strtotime((string) $r->punched_in_at)) / 60));
        }

        return $min / 60;
    }

    /** Book the payout so the balance reads zero: one adjustment per line, source payout:<leaving id>. */
    public static function bookPayout(int $agencyId, int $userId, int $leavingId, array $lines, ?int $by): int
    {
        $n = 0;
        foreach ($lines as $l) {
            $amt = round((float) ($l['balance'] ?? 0), 2);
            if (abs($amt) < 0.01) continue;
            $n += DB::table('hr_leave_ledger')->insertOrIgnore(['agency_id' => $agencyId, 'user_id' => $userId, 'leave_type_id' => (int) $l['leave_type_id'],
                'entry_date' => AgencyTime::today($agencyId), 'amount' => -$amt, 'kind' => 'adjustment', 'source_key' => 'payout:' . $leavingId,
                'reason' => 'Paid out on leaving', 'created_by_id' => $by, 'created_at' => now()]);
        }

        return $n;
    }

    /** Reverse what bookPayout wrote (undo a mistaken termination). */
    public static function reversePayout(int $agencyId, int $leavingId, ?int $by): int
    {
        $n = 0;
        foreach (DB::table('hr_leave_ledger')->where('agency_id', $agencyId)->where('source_key', 'payout:' . $leavingId)->get() as $r) {
            $n += DB::table('hr_leave_ledger')->insertOrIgnore(['agency_id' => $agencyId, 'user_id' => $r->user_id, 'leave_type_id' => $r->leave_type_id,
                'entry_date' => AgencyTime::today($agencyId), 'amount' => -(float) $r->amount, 'kind' => 'adjustment', 'source_key' => 'payout-undo:' . $leavingId,
                'reason' => 'Leaving undone: payout returned', 'created_by_id' => $by, 'created_at' => now()]);
        }

        return $n;
    }

    /** A returning employee starts at zero: bring each tracked balance back to 0 (source rehire:<leaving id>). */
    public static function zeroBalances(int $agencyId, int $userId, int $leavingId, ?int $by): int
    {
        $n = 0;
        foreach (HrLeave::summary($agencyId, $userId) as $t) {
            if (abs((float) $t['balance']) < 0.01) continue;
            $n += DB::table('hr_leave_ledger')->insertOrIgnore(['agency_id' => $agencyId, 'user_id' => $userId, 'leave_type_id' => $t['leave_type_id'],
                'entry_date' => AgencyTime::today($agencyId), 'amount' => -(float) $t['balance'], 'kind' => 'adjustment', 'source_key' => 'rehire:' . $leavingId,
                'reason' => 'Returning employee: new employment starts at zero', 'created_by_id' => $by, 'created_at' => now()]);
        }

        return $n;
    }
}
