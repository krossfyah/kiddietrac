<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KIDDIETRAC HR PHASE 4, BATCH 2 (2026-11-27): overtime, time in lieu, and timesheet approval. HR add-on only.
 *
 * OVERTIME. A staff member logs extra hours they worked (or their director / admin logs them for them): the date, how
 * long, why, and whether they would rather be paid or bank it. Somebody else decides (never the person themselves):
 *   - pay:  offered as a line on the person's next manual payslip (ManualPayrollController::prepare, overtime_id), the
 *           hours x the agency's overtime rate x the hourly rate typed in that run; commit() marks it paid, once.
 *   - bank: hours x the rate go into the "Overtime bank" leave type as a ledger row (kind overtime, source ot:<id>), so the
 *           person can book time in lieu through the ordinary time-off form. Balances are the SUM of the ledger, as always.
 * The rate is agencies.settings.hr_time.overtime.rate (default 1.0; an agency can set 1.5 or anything from 1 to 3) and is
 * copied onto the request when it is decided, so changing the setting never re-prices old overtime.
 *
 * TIMESHEETS. Per centre and period: scheduled (shifts, agency wall clock) against actual (time_punches, UTC), the paid
 * minutes after the agency's rules, and flags (still clocked in, a scheduled shift with no clock-in and no approved leave,
 * hours with no shift). Rules (agency settings, ALL OFF by default, so the numbers stay exactly what payroll reads today):
 *   - rounding: each clock-in and clock-out to the nearest 5 / 6 / 10 / 15 minutes;
 *   - clip_in / clip_out: time before the shift starts / after it ends is not counted (overtime is logged instead).
 * A director (own centres) or an admin approves a period that has ended; that locks it (hr_timesheet_locks). Nobody
 * approves a timesheet holding their own hours. A locked period refuses punch edits (EducatorRoomsController add / correct /
 * delete, the auto clock-out fix link); only an agency admin unlocks, with a reason, audited. Manual payroll warns about
 * periods that are not locked (or refuses, when the agency sets export_rule = block) and uses the paid minutes when any
 * rule is on.
 *
 * Settings: agencies.settings.hr_time (its own key; HrLeave::saveSettings rewrites settings.hr with fixed keys).
 */
final class HrTime
{
    public const DEFAULTS = [
        'overtime' => ['rate' => 1.0, 'staff_can_log' => true, 'allow_pay' => true, 'allow_bank' => true],
        'timesheet' => ['rounding' => 0, 'clip_in' => false, 'clip_out' => false, 'export_rule' => 'warn',
            // HR Phase 4 batch 8: automatic unpaid break, OFF by default (break_on false = numbers exactly as before).
            'break_on' => false, 'break_after_min' => 360, 'break_minutes' => 30],
    ];
    public const BREAK_AFTER = [240, 300, 330, 360, 390, 420, 480, 600];
    public const BREAK_MINUTES = [15, 20, 30, 45, 60];
    public const ROUNDING = [0, 5, 6, 10, 15];
    public const EXPORT_RULES = ['warn', 'block'];
    public const STATUSES = ['submitted' => 'Waiting for approval', 'approved' => 'Approved', 'paid' => 'Paid', 'declined' => 'Declined', 'withdrawn' => 'Withdrawn'];
    public const BANK_NAME = 'Overtime bank';
    public const MAX_SPAN_DAYS = 62;
    public const OT_COLS = ['id', 'agency_id', 'centre_id', 'user_id', 'work_date', 'minutes', 'reason', 'preference', 'logged_by_id', 'status', 'outcome',
        'rate', 'bank_hours', 'decided_by_id', 'decided_at', 'decision_comment', 'payroll_document_id', 'paid_at', 'created_at'];

    public static function ready(): bool
    {
        static $r = null;

        return $r ??= rescue(fn () => Schema::hasTable('hr_overtime_requests') && Schema::hasTable('hr_timesheet_locks'), false, false);
    }

    public static function on(?int $agencyId): bool
    {
        return $agencyId && self::ready() && HrLeave::on($agencyId);
    }

    // ── Settings ──────────────────────────────────────────────────────────────────────

    public static function settings(int $agencyId): array
    {
        $all = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true);
        $raw = is_array($all['hr_time'] ?? null) ? $all['hr_time'] : [];
        $out = [];
        foreach (self::DEFAULTS as $k => $def) {
            $out[$k] = array_replace($def, array_intersect_key(is_array($raw[$k] ?? null) ? $raw[$k] : [], $def));
        }
        $o = &$out['overtime'];
        $o['rate'] = round(max(1.0, min(3.0, (float) $o['rate'])), 2);
        foreach (['staff_can_log', 'allow_pay', 'allow_bank'] as $b) $o[$b] = (bool) $o[$b];
        if (! $o['allow_pay'] && ! $o['allow_bank']) $o['allow_pay'] = true;
        $t = &$out['timesheet'];
        $t['rounding'] = in_array((int) $t['rounding'], self::ROUNDING, true) ? (int) $t['rounding'] : 0;
        $t['clip_in'] = (bool) $t['clip_in'];
        $t['clip_out'] = (bool) $t['clip_out'];
        $t['export_rule'] = in_array($t['export_rule'], self::EXPORT_RULES, true) ? $t['export_rule'] : 'warn';
        $t['break_on'] = (bool) $t['break_on'];
        $t['break_after_min'] = in_array((int) $t['break_after_min'], self::BREAK_AFTER, true) ? (int) $t['break_after_min'] : 360;
        $t['break_minutes'] = in_array((int) $t['break_minutes'], self::BREAK_MINUTES, true) ? (int) $t['break_minutes'] : 30;
        unset($o, $t);
        $out['bank_type_id'] = isset($raw['bank_type_id']) ? (int) $raw['bank_type_id'] : null;

        return $out;
    }

    /** Merge-write agencies.settings.hr_time under a row lock (bank_type_id is kept unless given). */
    public static function saveSettings(int $agencyId, array $in): array
    {
        DB::transaction(function () use ($agencyId, $in) {
            $row = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->first(['settings']);
            $all = json_decode((string) ($row->settings ?? ''), true);
            $all = is_array($all) ? $all : [];
            $cur = is_array($all['hr_time'] ?? null) ? $all['hr_time'] : [];
            $all['hr_time'] = array_replace($cur, $in);
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        });

        return self::settings($agencyId);
    }

    public static function rulesOn(array $s): bool
    {
        $t = $s['timesheet'];

        return $t['rounding'] > 0 || $t['clip_in'] || $t['clip_out'] || ! empty($t['break_on']);
    }

    /**
     * AUTOMATIC UNPAID BREAK (HR Phase 4 batch 8, 2026-11-27). When the agency switches it on: one clock-in (one punch)
     * longer than break_after_min paid minutes has break_minutes taken off as an unpaid break. Applied in measure(), the
     * one place a punch becomes paid minutes, so the timesheet, the payroll summary / run / export, payslips and payee
     * invoices all take off exactly the same break. Someone who clocks out for their break and back in has two shorter
     * punches, so nothing is taken twice. Off = this returns the minutes unchanged.
     */
    public static function afterBreak(int $paid, array $t): int
    {
        if (empty($t['break_on']) || (int) ($t['break_minutes'] ?? 0) <= 0) return $paid;

        return $paid > (int) $t['break_after_min'] ? max(0, $paid - (int) $t['break_minutes']) : $paid;
    }

    // ── Overtime ──────────────────────────────────────────────────────────────────────

    /** The agency's "Overtime bank" leave type (hours, no accrual, carries over in full). Made the first time it is needed. */
    public static function bankType(int $agencyId, bool $create = false): ?object
    {
        $s = self::settings($agencyId);
        if ($s['bank_type_id']) {
            $t = DB::table('hr_leave_types')->where('id', $s['bank_type_id'])->where('agency_id', $agencyId)->first();
            if ($t) return $t;
        }
        if (! $create) return null;

        return DB::transaction(function () use ($agencyId) {
            DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->first(['id']);
            $s = self::settings($agencyId);   // re-read inside the lock: two approvals at once make one type
            if ($s['bank_type_id'] && ($t = DB::table('hr_leave_types')->where('id', $s['bank_type_id'])->where('agency_id', $agencyId)->first())) return $t;
            $id = (int) DB::table('hr_leave_types')->insertGetId(['agency_id' => $agencyId, 'name' => self::BANK_NAME, 'colour' => '#7C3AED', 'paid' => 1,
                'unit' => 'hours', 'visible_to_staff' => 1, 'needs_approval' => 1, 'maps_request_type' => null, 'accrual' => 'none',
                'carry_mode' => 'all', 'is_example' => 0, 'sort' => 90, 'created_at' => now(), 'updated_at' => now()]);
            self::saveSettings($agencyId, ['bank_type_id' => $id]);

            return DB::table('hr_leave_types')->where('id', $id)->first();
        });
    }

    public static function hoursText(float $h): string
    {
        $h = round($h, 2);
        $s = rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.');

        return $s . ' h';
    }

    /** "Overtime Nov 3: 2.5 h x 1.5" — the payslip line, also the bank ledger reason. */
    public static function label(object $o, ?float $rate = null): string
    {
        $d = substr((string) $o->work_date, 0, 10);
        $rate = $rate ?? (float) ($o->rate ?: 1);
        $r = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');

        return 'Overtime ' . ($d ? date('M j', strtotime($d)) : '') . ': ' . self::hoursText(((int) $o->minutes) / 60) . ($rate != 1.0 ? ' × ' . $r : '');
    }

    public static function shape(object $o, array $names, array $centres): array
    {
        return ['id' => (int) $o->id, 'user_id' => (int) $o->user_id, 'person' => $names[(int) $o->user_id] ?? '', 'work_date' => substr((string) $o->work_date, 0, 10),
            'minutes' => (int) $o->minutes, 'hours' => round(((int) $o->minutes) / 60, 2), 'reason' => $o->reason, 'preference' => $o->preference,
            'logged_by_id' => $o->logged_by_id ? (int) $o->logged_by_id : null, 'logged_by' => $o->logged_by_id && (int) $o->logged_by_id !== (int) $o->user_id ? ($names[(int) $o->logged_by_id] ?? '') : '',
            'status' => $o->status, 'outcome' => $o->outcome, 'rate' => $o->rate !== null ? (float) $o->rate : null, 'bank_hours' => $o->bank_hours !== null ? (float) $o->bank_hours : null,
            'decided_by' => $o->decided_by_id ? ($names[(int) $o->decided_by_id] ?? '') : '', 'decided_at' => $o->decided_at ? (string) $o->decided_at : null,
            'decision_comment' => $o->decision_comment, 'paid_at' => $o->paid_at ? (string) $o->paid_at : null,
            'payroll_document_id' => $o->payroll_document_id ? (int) $o->payroll_document_id : null,
            'centre_id' => $o->centre_id ? (int) $o->centre_id : null, 'centre' => $o->centre_id ? ($centres[(int) $o->centre_id] ?? null) : null,
            'created_at' => (string) $o->created_at];
    }

    /**
     * Approve as pay or bank. Returns null when done, or why not. The rate is fixed on the request now.
     * Banking writes ONE ledger row (source ot:<id>, idempotent); paying waits for the payroll run.
     */
    public static function approve(object $o, string $outcome, int $actorId, ?string $comment): ?string
    {
        $aid = (int) $o->agency_id;
        $s = self::settings($aid)['overtime'];
        if ($outcome === 'pay' && ! $s['allow_pay']) return 'Your agency banks overtime as time in lieu; it is not paid out.';
        if ($outcome === 'bank' && ! $s['allow_bank']) return 'Your agency pays overtime; it does not bank it as time in lieu.';
        $rate = (float) $s['rate'];
        $err = null;
        DB::transaction(function () use ($o, $outcome, $actorId, $comment, $aid, $rate, &$err) {
            $cur = DB::table('hr_overtime_requests')->where('id', $o->id)->lockForUpdate()->first(['status']);
            if (! $cur || ! in_array($cur->status, ['submitted', 'declined'], true)) { $err = 'This overtime has already been decided.'; return; }
            $bank = null;
            if ($outcome === 'bank') {
                $type = self::bankType($aid, true);
                if (! $type || $type->archived_at) { $err = 'The Overtime bank leave type is archived. Restore it in HR > Leave types first.'; return; }
                $bank = round(((int) $o->minutes) / 60 * $rate, 2);
                DB::table('hr_leave_ledger')->insertOrIgnore(['agency_id' => $aid, 'user_id' => (int) $o->user_id, 'leave_type_id' => (int) $type->id,
                    'entry_date' => AgencyTime::today($aid), 'amount' => $bank, 'kind' => 'overtime', 'source_key' => 'ot:' . (int) $o->id,
                    'reason' => self::label($o, $rate) . ' banked', 'created_by_id' => $actorId, 'created_at' => now()]);
            }
            DB::table('hr_overtime_requests')->where('id', $o->id)->update(['status' => 'approved', 'outcome' => $outcome, 'rate' => $rate, 'bank_hours' => $bank,
                'decided_by_id' => $actorId, 'decided_at' => now(), 'decision_comment' => $comment, 'updated_at' => now()]);
        });

        return $err;
    }

    /**
     * Approved, unpaid overtime per person as payroll lines (ManualPayrollController::prepare): the hours and the multiplier;
     * the screen works out the amount from the hourly rate typed for that person in the run.
     * @return array<int, array<int, array{overtime_id:int,label:string,hours:float,multiplier:float}>>
     */
    public static function forPayroll(int $agencyId, array $userIds): array
    {
        if (! self::ready() || ! $userIds) return [];
        $out = [];
        foreach (DB::table('hr_overtime_requests')->where('agency_id', $agencyId)->where('status', 'approved')->where('outcome', 'pay')
            ->whereNull('payroll_document_id')->whereIn('user_id', $userIds)->orderBy('work_date')->get(['id', 'user_id', 'work_date', 'minutes', 'rate']) as $o) {
            $out[(int) $o->user_id][] = ['overtime_id' => (int) $o->id, 'label' => self::label($o), 'hours' => round(((int) $o->minutes) / 60, 2),
                'multiplier' => (float) ($o->rate ?: 1)];
        }

        return $out;
    }

    /** Mark the overtime a payslip pays: only this person's, this agency's, approved as pay and unpaid (never twice). */
    public static function markPaid(int $agencyId, int $userId, array $ids, int $payrollDocumentId): int
    {
        if (! self::ready() || ! $ids) return 0;

        return DB::table('hr_overtime_requests')->whereIn('id', $ids)->where('agency_id', $agencyId)->where('user_id', $userId)
            ->where('status', 'approved')->where('outcome', 'pay')->whereNull('payroll_document_id')
            ->update(['status' => 'paid', 'payroll_document_id' => $payrollDocumentId, 'paid_at' => now(), 'updated_at' => now()]);
    }

    // ── Timesheets ────────────────────────────────────────────────────────────────────

    /** [tz, fromUtc, toUtc) for a centre's agency days from..to. */
    public static function window(int $centreId, string $from, string $to): array
    {
        $tz = AgencyTime::tzForCentre($centreId);
        [$a, $b] = AgencyTime::spanRangeForCentre($centreId, $from, $to);

        return [$tz, $a, $b];
    }

    /** Shifts at a centre for from..to (wall clock), per user. */
    private static function shifts(int $centreId, string $from, string $to, ?array $userIds = null)
    {
        return DB::table('shifts as s')->join('rooms as r', 'r.id', '=', 's.room_id')
            ->where('r.centre_id', $centreId)->whereNotIn('s.status', ['cancelled'])
            ->where('s.starts_at', '<', Carbon::parse($to)->addDay()->format('Y-m-d 00:00:00'))
            ->where('s.ends_at', '>', $from . ' 00:00:00')
            ->when($userIds !== null, fn ($q) => $q->whereIn('s.user_id', $userIds ?: [0]))
            ->orderBy('s.starts_at')->get(['s.id', 's.user_id', 's.starts_at', 's.ends_at', 'r.name as room'])
            ->groupBy('user_id');
    }

    private static function roundTo(Carbon $c, int $n): Carbon
    {
        if ($n <= 0) return $c;
        $m = $c->hour * 60 + $c->minute + $c->second / 60;

        return $c->copy()->startOfDay()->addMinutes((int) (round($m / $n) * $n));
    }

    /**
     * HOURS MUST AGREE (2026-11-27, Anthony: Staff > Timesheets and the payroll summary disagreed - Test Agency
     * 14438.69 h vs 14421.96 h). THE one way a punch becomes minutes, for every screen, report and export: whole
     * minutes between clock-in and clock-out, seconds dropped - exactly what MySQL's TIMESTAMPDIFF(MINUTE, in, out)
     * gives the payroll summary, the payroll run, payslips and the payroll export. Carbon 3's diffInMinutes() is a
     * FLOAT that keeps the seconds, so the timesheet summed fractions of a minute that payroll never paid; and a punch
     * whose clock-out is before its clock-in counts 0 (the SQL sums SUBTRACTED it: 6 such punches = 16.3 h on agency 6).
     * Times are read as stored (UTC), as TIMESTAMPDIFF does, so a daylight-saving night never adds or loses an hour.
     */
    public static function punchMinutes($in, $out): int
    {
        if (empty($in) || empty($out)) return 0;
        $a = $in instanceof \DateTimeInterface ? $in->getTimestamp() : Carbon::parse((string) $in, 'UTC')->getTimestamp();
        $b = $out instanceof \DateTimeInterface ? $out->getTimestamp() : Carbon::parse((string) $out, 'UTC')->getTimestamp();

        return intdiv(max(0, $b - $a), 60);
    }

    /** Minutes to hours for display: rounded ONCE, on the total (a total is never a sum of rounded parts). */
    public static function hours($minutes): float
    {
        return round(((int) $minutes) / 60, 2);
    }

    /**
     * One punch, raw and after the rules. $shifts = that person's shifts at the centre (wall clock in $tz).
     * @return array{raw:int,paid:int,shift:?object,in:Carbon,out:?Carbon,pin:?Carbon,pout:?Carbon}
     */
    public static function measure(object $p, $shifts, string $tz, array $t): array
    {
        $in = Carbon::parse($p->punched_in_at, 'UTC')->setTimezone($tz);
        $out = $p->punched_out_at ? Carbon::parse($p->punched_out_at, 'UTC')->setTimezone($tz) : null;
        $best = null;
        $bestOv = 0;
        foreach ($shifts ?: [] as $s) {
            $ss = Carbon::parse($s->starts_at, $tz);
            $se = Carbon::parse($s->ends_at, $tz);
            $end = $out ?: $in->copy()->addMinute();
            $ov = min($end->getTimestamp(), $se->getTimestamp()) - max($in->getTimestamp(), $ss->getTimestamp());
            if ($ov > $bestOv) { $bestOv = $ov; $best = $s; }
        }
        if (! $out) return ['raw' => 0, 'paid' => 0, 'shift' => $best, 'in' => $in, 'out' => null, 'pin' => null, 'pout' => null];
        $raw = intdiv(max(0, $out->getTimestamp() - $in->getTimestamp()), 60);
        $pin = $in->copy();
        $pout = $out->copy();
        if ($best) {
            $ss = Carbon::parse($best->starts_at, $tz);
            $se = Carbon::parse($best->ends_at, $tz);
            if ($t['clip_in'] && $pin->lt($ss)) $pin = $ss->copy();
            if ($t['clip_out'] && $pout->gt($se)) $pout = $se->copy();
        }
        $pin = self::roundTo($pin, (int) $t['rounding']);
        $pout = self::roundTo($pout, (int) $t['rounding']);
        $paid = self::rulesOn(['timesheet' => $t]) ? self::afterBreak(intdiv(max(0, $pout->getTimestamp() - $pin->getTimestamp()), 60), $t) : $raw;

        return ['raw' => $raw, 'paid' => $paid, 'shift' => $best, 'in' => $in, 'out' => $out, 'pin' => $pin, 'pout' => $pout];
    }

    /**
     * The paid minutes of closed punches under the agency's rules, [punch id => minutes], for payroll. Only called when a
     * rule is on; punches need id, user_id, centre_id, punched_in_at, punched_out_at.
     */
    public static function paidMinutes(int $agencyId, $punches, string $from, string $to): array
    {
        $t = self::settings($agencyId)['timesheet'];
        $out = [];
        foreach (collect($punches)->groupBy('centre_id') as $cid => $rows) {
            $tz = AgencyTime::tzForCentre((int) $cid);
            $sh = self::shifts((int) $cid, Carbon::parse($from)->subDay()->toDateString(), Carbon::parse($to)->addDay()->toDateString(),
                $rows->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->values()->all());
            foreach ($rows as $p) {
                if (! $p->punched_out_at) continue;
                $out[(int) $p->id] = self::measure($p, $sh[$p->user_id] ?? [], $tz, $t)['paid'];
            }
        }

        return $out;
    }

    /** @var array<int,bool> agency => a rounding / clip rule is on (per process) */
    private static array $rulesMemo = [];

    /** Does this agency have the HR add-on AND a rounding, clip-to-shift or automatic-break rule switched on? */
    public static function rulesActive(?int $agencyId): bool
    {
        if (! $agencyId) return false;

        return self::$rulesMemo[$agencyId] ??= self::on($agencyId) && self::rulesOn(self::settings($agencyId));
    }

    public static function forgetRules(): void
    {
        self::$rulesMemo = [];
    }

    /**
     * THE ONE PAID-HOURS RULE FOR EVERY OUTPUT (2026-11-27). Staff > Timesheets, the payroll summary / period / report /
     * CSV, the payroll export, payslip hours (Payroll::units) and payee-invoice hours all ask this, as the manual payroll
     * run does (paidMinutes): the same punches give the same paid minutes everywhere.
     *
     * Returns null when NO agency these punches belong to (by their centre) has a rule on - the caller then keeps its own
     * figures exactly as they were (byte-identical output). Otherwise [punch id => minutes] for every closed punch: the
     * rules applied for an agency that has them, the raw clock minutes for one that does not.
     * Punches need id, user_id, centre_id, punched_in_at, punched_out_at.
     */
    public static function paidMap($punches): ?array
    {
        $closed = collect($punches)->filter(fn ($p) => ! empty($p->punched_out_at));
        if ($closed->isEmpty()) return null;
        $centreAgency = DB::table('centres')->whereIn('id', $closed->pluck('centre_id')->filter()->unique()->values()->all() ?: [0])->pluck('agency_id', 'id')->all();
        $byAgency = $closed->groupBy(fn ($p) => (int) ($centreAgency[(int) $p->centre_id] ?? 0));
        if (! $byAgency->keys()->contains(fn ($a) => self::rulesActive((int) $a))) return null;
        $out = [];
        foreach ($byAgency as $aid => $rows) {
            if (self::rulesActive((int) $aid)) {
                $from = Carbon::parse((string) $rows->min('punched_in_at'))->toDateString();
                $to = Carbon::parse((string) $rows->max('punched_in_at'))->toDateString();
                $out += self::paidMinutes((int) $aid, $rows, $from, $to);
            } else {
                foreach ($rows as $p) {
                    $out[(int) $p->id] = intdiv(max(0, Carbon::parse($p->punched_out_at)->getTimestamp() - Carbon::parse($p->punched_in_at)->getTimestamp()), 60);
                }
            }
        }

        return $out;
    }

    /**
     * paidMap() for an aggregate query: $base = the time_punches query (alias $alias) BEFORE its select / groupBy. null =
     * no rule applies (keep the SQL sums); otherwise [key => paid minutes], key = the $keyCols values joined by "|".
     */
    public static function paidSumsFor($base, string $alias, array $keyCols = []): ?array
    {
        $sel = [$alias . '.id', $alias . '.user_id', $alias . '.centre_id', $alias . '.punched_in_at', $alias . '.punched_out_at'];
        foreach (array_values($keyCols) as $i => $c) $sel[] = $c . ' as __k' . $i;
        $rows = (clone $base)->select($sel)->get();
        $map = self::paidMap($rows);
        if ($map === null) return null;
        $out = [];
        foreach ($rows as $r) {
            if (! isset($map[(int) $r->id])) continue;
            $k = [];
            foreach (array_keys(array_values($keyCols)) as $i) $k[] = (string) $r->{'__k' . $i};
            $key = implode('|', $k);
            $out[$key] = ($out[$key] ?? 0) + $map[(int) $r->id];
        }

        return $out;
    }

    /** Approved leave days per person, [user_id => [Y-m-d => true]], for from..to. */
    private static function leaveDays(int $agencyId, array $userIds, string $from, string $to): array
    {
        $out = [];
        foreach (DB::table('time_off_requests')->where('agency_id', $agencyId)->where('status', 'approved')->whereIn('user_id', $userIds ?: [0])
            ->where('start_at', '<=', $to)->where('end_at', '>=', $from)->get(['user_id', 'start_at', 'end_at', 'all_day']) as $r) {
            $d = max($from, substr((string) $r->start_at, 0, 10));
            $e = min($to, substr((string) $r->end_at, 0, 10));
            for ($i = 0; $d <= $e && $i < 400; $i++, $d = date('Y-m-d', strtotime($d . ' +1 day'))) $out[(int) $r->user_id][$d] = true;
        }

        return $out;
    }

    /** The locked row covering a centre's agency date, if any. */
    public static function lockOn(int $centreId, string $date): ?object
    {
        if (! self::ready()) return null;

        return DB::table('hr_timesheet_locks')->where('centre_id', $centreId)->where('status', 'locked')
            ->where('period_start', '<=', $date)->where('period_end', '>=', $date)->first();
    }

    /** Locked rows of centres overlapping from..to. */
    public static function locksFor(array $centreIds, string $from, string $to)
    {
        if (! self::ready()) return collect();

        return DB::table('hr_timesheet_locks')->whereIn('centre_id', $centreIds ?: [0])->where('status', 'locked')
            ->where('period_start', '<=', $to)->where('period_end', '>=', $from)->orderBy('period_start')->get();
    }

    public static function periodText(object $l): string
    {
        $a = substr((string) $l->period_start, 0, 10);
        $b = substr((string) $l->period_end, 0, 10);

        return date('M j', strtotime($a)) . ' – ' . date('M j, Y', strtotime($b));
    }

    /**
     * Why a punch change is refused: a time it has (or would have) falls on a day of a locked period at its centre.
     * Null = allowed (no HR add-on, or nothing locked). $utcTimes = the punch's clock-in times, before and after.
     */
    public static function editRefusal(?int $centreId, array $utcTimes): ?string
    {
        if (! $centreId || ! self::ready()) return null;
        $aid = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
        if (! self::on($aid)) return null;
        $tz = AgencyTime::tz($aid);
        foreach (array_filter($utcTimes) as $ts) {
            $d = Carbon::parse((string) $ts, 'UTC')->setTimezone($tz)->toDateString();
            if ($l = self::lockOn($centreId, $d)) {
                return 'These hours are in a timesheet that was approved and locked for payroll (' . self::periodText($l) . '). An agency admin can unlock it in HR > Timesheets first.';
            }
        }

        return null;
    }

    /**
     * Centres (of $centreIds) that have clock-ins in from..to on days no locked period covers: [centre_id => name].
     */
    public static function uncovered(int $agencyId, array $centreIds, string $from, string $to): array
    {
        if (! self::on($agencyId) || ! $centreIds) return [];
        $out = [];
        $locks = self::locksFor($centreIds, $from, $to)->groupBy('centre_id');
        foreach (DB::table('centres')->whereIn('id', $centreIds)->where('agency_id', $agencyId)->get(['id', 'name']) as $c) {
            [, $a, $b] = self::window((int) $c->id, $from, $to);
            if (! DB::table('time_punches')->where('centre_id', $c->id)->where('punched_in_at', '>=', $a)->where('punched_in_at', '<', $b)->exists()) continue;
            $covered = [];
            foreach ($locks[$c->id] ?? [] as $l) {
                $d = max($from, substr((string) $l->period_start, 0, 10));
                $e = min($to, substr((string) $l->period_end, 0, 10));
                for ($i = 0; $d <= $e && $i < 400; $i++, $d = date('Y-m-d', strtotime($d . ' +1 day'))) $covered[$d] = true;
            }
            for ($d = $from, $i = 0; $d <= $to && $i < 400; $i++, $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                if (empty($covered[$d])) { $out[(int) $c->id] = $c->name; break; }
            }
        }

        return $out;
    }

    /**
     * A centre's timesheet for from..to: one row per person (scheduled, worked, paid, variance, flags) and, with $detail,
     * each punch. $people limits it (a director's own staff); outsiders are never listed.
     */
    public static function sheet(int $agencyId, int $centreId, string $from, string $to, ?int $onlyUser = null): array
    {
        $s = self::settings($agencyId);
        $t = $s['timesheet'];
        [$tz, $a, $b] = self::window($centreId, $from, $to);
        $nowLocal = Carbon::now($tz);
        $outsiders = AgencySignatories::outsiderIds();
        $punches = DB::table('time_punches')->where('centre_id', $centreId)->where('punched_in_at', '>=', $a)->where('punched_in_at', '<', $b)
            ->when($onlyUser, fn ($q) => $q->where('user_id', $onlyUser))
            ->orderBy('punched_in_at')->get(['id', 'user_id', 'centre_id', 'punched_in_at', 'punched_out_at', 'source', 'notes', 'corrected_at'])
            ->groupBy('user_id');
        $shifts = self::shifts($centreId, $from, $to, $onlyUser ? [$onlyUser] : null);
        $ids = collect(array_keys($punches->all()))->merge(array_keys($shifts->all()))->map(fn ($v) => (int) $v)->unique()
            ->reject(fn ($v) => in_array($v, $outsiders, true))->values()->all();
        $names = DB::table('users')->whereIn('id', $ids ?: [0])->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($u) => [(int) $u->id => trim($u->first_name . ' ' . $u->last_name)])->all();
        $leave = self::leaveDays($agencyId, $ids, $from, $to);
        $ot = DB::table('hr_overtime_requests')->where('agency_id', $agencyId)->whereIn('user_id', $ids ?: [0])->whereIn('status', ['approved', 'paid'])
            ->whereBetween('work_date', [$from, $to])->selectRaw('user_id, SUM(minutes) m')->groupBy('user_id')->pluck('m', 'user_id')->all();
        // HR Phase 4 batch 8: contracted hours for the period = the employee file's hours a week x days / 7 (null = not set).
        $hpw = rescue(fn () => DB::table('hr_employee_files')->where('agency_id', $agencyId)->whereIn('user_id', $ids ?: [0])->whereNotNull('hours_per_week')
            ->pluck('hours_per_week', 'user_id')->all(), [], false);
        $days = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
        $rows = [];
        $detail = [];
        foreach ($ids as $uid) {
            $mine = $shifts[$uid] ?? collect();
            $r = ['user_id' => $uid, 'name' => $names[$uid] ?? '', 'scheduled_min' => 0, 'worked_min' => 0, 'paid_min' => 0, 'shifts' => 0, 'punches' => 0,
                'open' => 0, 'no_show' => 0, 'unscheduled' => 0, 'overtime_min' => (int) ($ot[$uid] ?? 0),
                'contracted_min' => isset($hpw[$uid]) ? (int) round(((float) $hpw[$uid]) * 60 * $days / 7) : null];
            $used = [];
            foreach ($mine as $sh) {
                $ss = Carbon::parse($sh->starts_at, $tz);
                $se = Carbon::parse($sh->ends_at, $tz);
                if ($ss->toDateString() < $from || $ss->toDateString() > $to) continue;
                $r['shifts']++;
                $r['scheduled_min'] += intdiv(max(0, $se->getTimestamp() - $ss->getTimestamp()), 60);
            }
            foreach ($punches[$uid] ?? [] as $p) {
                $m = self::measure($p, $mine, $tz, $t);
                $r['punches']++;
                $r['worked_min'] += $m['raw'];
                $r['paid_min'] += $m['paid'];
                if (! $p->punched_out_at) $r['open']++;
                if ($m['shift']) $used[(int) $m['shift']->id] = true; elseif ($p->punched_out_at) $r['unscheduled']++;
                if ($onlyUser) {
                    $detail[] = ['id' => (int) $p->id, 'date' => $m['in']->toDateString(), 'in' => $m['in']->format('g:i A'), 'out' => $m['out'] ? $m['out']->format('g:i A') : null,
                        'out_date' => $m['out'] ? $m['out']->toDateString() : null, 'raw_min' => $m['raw'], 'paid_min' => $m['paid'],
                        'paid_in' => $m['pin'] ? $m['pin']->format('g:i A') : null, 'paid_out' => $m['pout'] ? $m['pout']->format('g:i A') : null,
                        'shift' => $m['shift'] ? Carbon::parse($m['shift']->starts_at, $tz)->format('g:i A') . ' – ' . Carbon::parse($m['shift']->ends_at, $tz)->format('g:i A') : null,
                        'room' => $m['shift']->room ?? null, 'open' => ! $p->punched_out_at, 'auto' => $p->source === 'auto', 'corrected' => (bool) $p->corrected_at];
                }
            }
            foreach ($mine as $sh) {
                $ss = Carbon::parse($sh->starts_at, $tz);
                $se = Carbon::parse($sh->ends_at, $tz);
                $d = $ss->toDateString();
                if ($d < $from || $d > $to || isset($used[(int) $sh->id]) || $se->gt($nowLocal) || ! empty($leave[$uid][$d])) continue;
                $r['no_show']++;
                if ($onlyUser) $detail[] = ['id' => null, 'date' => $d, 'no_show' => true, 'shift' => $ss->format('g:i A') . ' – ' . $se->format('g:i A'), 'room' => $sh->room];
            }
            $r['variance_min'] = $r['paid_min'] - $r['scheduled_min'];
            $r['flags'] = $r['open'] + $r['no_show'];
            $rows[] = $r;
        }
        usort($rows, fn ($x, $y) => strcmp($x['name'], $y['name']));
        usort($detail, fn ($x, $y) => strcmp($x['date'] . ($x['in'] ?? ''), $y['date'] . ($y['in'] ?? '')));
        $sum = fn ($k) => array_sum(array_column($rows, $k));

        return ['rows' => $rows, 'detail' => $detail, 'tz' => $tz, 'rules' => $t, 'rules_on' => self::rulesOn($s),
            'totals' => ['scheduled_min' => $sum('scheduled_min'), 'worked_min' => $sum('worked_min'), 'paid_min' => $sum('paid_min'), 'contracted_min' => $sum('contracted_min'),
                'open' => $sum('open'), 'no_show' => $sum('no_show'), 'unscheduled' => $sum('unscheduled'), 'people' => count($rows)]];
    }

    public static function lockShape(object $l, array $names): array
    {
        return ['id' => (int) $l->id, 'centre_id' => (int) $l->centre_id, 'period_start' => substr((string) $l->period_start, 0, 10),
            'period_end' => substr((string) $l->period_end, 0, 10), 'status' => $l->status, 'approved_by' => $names[(int) $l->approved_by_id] ?? '',
            'approved_at' => $l->approved_at ? (string) $l->approved_at : null, 'note' => $l->note,
            'unlocked_by' => $l->unlocked_by_id ? ($names[(int) $l->unlocked_by_id] ?? '') : '', 'unlocked_at' => $l->unlocked_at ? (string) $l->unlocked_at : null,
            'unlock_reason' => $l->unlock_reason];
    }
}
