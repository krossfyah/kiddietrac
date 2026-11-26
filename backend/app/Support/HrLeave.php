<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KIDDIETRAC HR: LEAVE BALANCES (2026-11-27, Phase 1 of the HR add-on, feature key `hr`).
 *
 * Anthony's rules:
 *  - Leave rules are FULLY CUSTOM per agency. Nothing here knows any province's law; an agency enters its own types and
 *    rules. EXAMPLE_SET is an optional, clearly labelled starting point an admin can load and edit. It is never applied
 *    by itself.
 *  - A balance is the SUM of hr_leave_ledger rows. A row is never edited or deleted: a correction is a new row.
 *  - The existing time-off flow is extended, not copied: approving a request writes a "taken" row, and denying or
 *    withdrawing it after approval writes the "returned" row (syncRequest).
 *
 * Settings live in agencies.settings.hr. All dates are the agency's (AgencyTime); stored instants stay UTC.
 */
final class HrLeave
{
    public const FEATURE = 'hr';
    public const UNITS = ['days', 'hours'];
    public const ACCRUALS = ['lump', 'pay_period', 'hours_worked', 'none'];
    public const CARRY = ['none', 'all', 'capped'];
    public const PERIODS = [12, 24, 26, 52];
    public const EMPLOYMENT = ['full_time' => 'Full-time', 'part_time' => 'Part-time', 'casual' => 'Casual', 'student' => 'Student', 'contract' => 'Contract'];
    public const STATUSES = ['active' => 'Active', 'on_leave' => 'On leave', 'terminated' => 'Terminated'];
    public const REQUEST_TYPES = ['vacation' => 'Vacation', 'sick' => 'Sick', 'personal' => 'Personal', 'bereavement' => 'Bereavement', 'jury' => 'Jury duty', 'other' => 'Other'];
    public const STAFF_ROLES = ['agency_admin', 'centre_director', 'educator', 'home_visitor'];

    public const DEFAULTS = [
        'leave_year' => 'fixed',          // fixed | anniversary
        'leave_year_start' => '01-01',    // MM-DD, for fixed
        'allow_negative' => false,
        'hours_per_day' => 8,
        'short_absence' => ['max_days' => 2, 'count' => 3, 'months' => 6],
    ];

    /**
     * AN EXAMPLE, NOT A RULE. Loaded only when an admin presses "Load example set", and every value is then theirs to
     * change. Named so nobody mistakes it for a legal minimum.
     */
    public const EXAMPLE_SET = [
        ['name' => 'Vacation', 'colour' => '#0E7490', 'paid' => true, 'unit' => 'days', 'visible_to_staff' => true, 'needs_approval' => true,
            'maps_request_type' => 'vacation', 'accrual' => 'lump', 'carry_mode' => 'capped', 'carry_cap' => 5, 'carry_expiry_months' => 3,
            'rules' => [['employment_type' => null, 'min_years' => 0, 'amount' => 10], ['employment_type' => null, 'min_years' => 5, 'amount' => 15]]],
        ['name' => 'Sick days', 'colour' => '#DC2626', 'paid' => true, 'unit' => 'days', 'visible_to_staff' => true, 'needs_approval' => false,
            'maps_request_type' => 'sick', 'accrual' => 'lump', 'carry_mode' => 'none',
            'rules' => [['employment_type' => null, 'min_years' => 0, 'amount' => 5]]],
        ['name' => 'Personal days', 'colour' => '#7C3AED', 'paid' => true, 'unit' => 'days', 'visible_to_staff' => true, 'needs_approval' => true,
            'maps_request_type' => 'personal', 'accrual' => 'lump', 'carry_mode' => 'none',
            'rules' => [['employment_type' => null, 'min_years' => 0, 'amount' => 2]]],
        ['name' => 'Bereavement', 'colour' => '#475569', 'paid' => true, 'unit' => 'days', 'visible_to_staff' => true, 'needs_approval' => true,
            'maps_request_type' => 'bereavement', 'accrual' => 'none', 'carry_mode' => 'none', 'rules' => []],
        ['name' => 'Unpaid leave', 'colour' => '#B45309', 'paid' => false, 'unit' => 'days', 'visible_to_staff' => true, 'needs_approval' => true,
            'maps_request_type' => 'other', 'accrual' => 'none', 'carry_mode' => 'none', 'rules' => []],
    ];

    /** Is the HR add-on on for this agency, and are its tables there? */
    public static function on(?int $agencyId): bool
    {
        if (! $agencyId) {
            return false;
        }
        static $ready = null;
        if ($ready === null) {
            $ready = rescue(fn () => Schema::hasTable('hr_leave_ledger') && Schema::hasColumn('time_off_requests', 'leave_type_id'), false, false);
        }

        return $ready && Features::on($agencyId, self::FEATURE);
    }

    public static function settings(int $agencyId): array
    {
        $raw = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];
        $hr = is_array($raw['hr'] ?? null) ? $raw['hr'] : [];
        $out = array_replace(self::DEFAULTS, array_intersect_key($hr, self::DEFAULTS));
        $out['short_absence'] = array_replace(self::DEFAULTS['short_absence'], is_array($hr['short_absence'] ?? null) ? $hr['short_absence'] : []);
        $out['allow_negative'] = (bool) $out['allow_negative'];
        $out['hours_per_day'] = max(1, min(24, (float) $out['hours_per_day']));
        if (! preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', (string) $out['leave_year_start'])) {
            $out['leave_year_start'] = '01-01';
        }
        $out['leave_year'] = $out['leave_year'] === 'anniversary' ? 'anniversary' : 'fixed';

        return $out;
    }

    /** Merge-write agencies.settings.hr, under a row lock so another settings save is never lost. */
    public static function saveSettings(int $agencyId, array $hr): array
    {
        DB::transaction(function () use ($agencyId, $hr) {
            $row = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->first(['settings']);
            $all = json_decode((string) ($row->settings ?? ''), true) ?: [];
            $all['hr'] = $hr;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        });

        return self::settings($agencyId);
    }

    /** Active (not archived) leave types of an agency, in their order. */
    public static function types(int $agencyId, bool $withArchived = false)
    {
        return DB::table('hr_leave_types')->where('agency_id', $agencyId)
            ->when(! $withArchived, fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('sort')->orderBy('name')->get();
    }

    /** The type a request counts against: its own, or the agency type its old request_type maps onto. */
    public static function typeForRequest(object $row): ?object
    {
        if (! empty($row->leave_type_id)) {
            return DB::table('hr_leave_types')->where('id', $row->leave_type_id)->where('agency_id', $row->agency_id)->first();
        }
        if (empty($row->request_type)) {
            return null;
        }

        return DB::table('hr_leave_types')->where('agency_id', $row->agency_id)->whereNull('archived_at')
            ->where('maps_request_type', $row->request_type)->orderBy('sort')->orderBy('id')->first();
    }

    // ── People and dates ──────────────────────────────────────────────────────────────

    public static function file(int $agencyId, int $userId): ?object
    {
        return DB::table('hr_employee_files')->where('agency_id', $agencyId)->where('user_id', $userId)->first();
    }

    /** Staff of the agency who hold a live staff role (agency level, or at an open centre of it). @return int[] */
    public static function staffIds(int $agencyId, ?array $centreScope = null): array
    {
        $open = Live::centreIds($agencyId);
        $q = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', 1)->whereIn('ra.role', self::STAFF_ROLES)->whereNull('u.deleted_at')
            ->where(function ($w) use ($agencyId, $open) {
                $w->where(fn ($a) => $a->where('ra.agency_id', $agencyId)->whereNull('ra.centre_id'))
                  ->orWhereIn('ra.centre_id', $open ?: [0]);
            });
        if ($centreScope !== null) {
            $q->whereIn('ra.centre_id', array_values(array_intersect($centreScope, $open)) ?: [0]);
        }

        return $q->distinct()->pluck('u.id')->map(fn ($v) => (int) $v)->all();
    }

    /** First day of the leave year that $date falls in, for this person. */
    public static function yearStart(int $agencyId, int $userId, string $date, ?array $settings = null, ?string $hireDate = null): string
    {
        $s = $settings ?: self::settings($agencyId);
        $md = $s['leave_year_start'];
        if ($s['leave_year'] === 'anniversary') {
            $hire = $hireDate ?? DB::table('users')->where('id', $userId)->value('hire_date');
            if ($hire) {
                $md = substr((string) $hire, 5, 5);
            }
        }
        if ($md === '02-29') {
            $md = '02-28';
        }
        $y = (int) substr($date, 0, 4);
        $cand = $y . '-' . $md;

        return $cand <= $date ? $cand : ($y - 1) . '-' . $md;
    }

    public static function addYears(string $ymd, int $n): string
    {
        return Carbon::createFromFormat('Y-m-d', $ymd, 'UTC')->startOfDay()->addYearsNoOverflow($n)->toDateString();
    }

    public static function serviceYears(?string $hireDate, string $onDate): float
    {
        if (! $hireDate || $hireDate > $onDate) {
            return 0.0;
        }

        return round(Carbon::parse(substr($hireDate, 0, 10))->floatDiffInYears(Carbon::parse($onDate)), 2);
    }

    /** The entitlement rule that applies: most years of service reached, a named employment type beating "any". */
    public static function entitlement(object $type, ?string $employmentType, float $years): ?float
    {
        $best = null;
        foreach (DB::table('hr_leave_rules')->where('leave_type_id', $type->id)->get() as $r) {
            if ($r->employment_type !== null && $r->employment_type !== $employmentType) {
                continue;
            }
            if ((float) $r->min_years > $years + 0.0001) {
                continue;
            }
            $rank = [(float) $r->min_years, $r->employment_type !== null ? 1 : 0];
            if ($best === null || $rank > $best[0]) {
                $best = [$rank, (float) $r->amount];
            }
        }

        return $best ? $best[1] : null;
    }

    // ── Balances ──────────────────────────────────────────────────────────────────────

    public static function balance(int $userId, int $typeId): float
    {
        return round((float) DB::table('hr_leave_ledger')->where('user_id', $userId)->where('leave_type_id', $typeId)->sum('amount'), 2);
    }

    /** Amount of this person's PENDING requests against a type (not yet deducted). */
    public static function pending(int $agencyId, int $userId, object $type, ?int $exceptRequestId = null): float
    {
        $sum = 0.0;
        foreach (DB::table('time_off_requests')->where('agency_id', $agencyId)->where('user_id', $userId)->where('status', 'pending')
            ->when($exceptRequestId, fn ($q) => $q->where('id', '!=', $exceptRequestId))->get() as $r) {
            $t = self::typeForRequest($r);
            if ($t && (int) $t->id === (int) $type->id) {
                $sum += self::amountFor($r, $t);
            }
        }

        return round($sum, 2);
    }

    /** balance + pending + unit for each type, for one person. $staffView = only the types shown to staff. */
    public static function summary(int $agencyId, int $userId, bool $staffView = false): array
    {
        $out = [];
        foreach (self::types($agencyId) as $t) {
            if ($staffView && ! $t->visible_to_staff) {
                continue;
            }
            $bal = self::balance($userId, (int) $t->id);
            $pend = self::pending($agencyId, $userId, $t);
            $out[] = ['leave_type_id' => (int) $t->id, 'name' => $t->name, 'colour' => $t->colour, 'unit' => $t->unit,
                'paid' => (bool) $t->paid, 'needs_approval' => (bool) $t->needs_approval, 'tracked' => $t->accrual !== 'none' || self::hasRows($userId, (int) $t->id),
                'balance' => $bal, 'pending' => $pend, 'after_pending' => round($bal - $pend, 2)];
        }

        return $out;
    }

    private static function hasRows(int $userId, int $typeId): bool
    {
        return DB::table('hr_leave_ledger')->where('user_id', $userId)->where('leave_type_id', $typeId)->exists();
    }

    /**
     * How much of a type's unit a request uses. WORKING days for all-day leave: weekdays, less the statutory holidays and
     * closures already on the person's centre calendar (Anthony, 2026-11-26); a weekend-only request counts its calendar
     * days. Part of a day counts its hours (nothing on a closed day). Hours = days x the agency's hours per day.
     */
    public static function amountFor(object $row, object $type, ?array $settings = null): float
    {
        if (isset($row->leave_amount) && $row->leave_amount !== null && (int) ($row->leave_type_id ?? 0) === (int) $type->id) {
            return (float) $row->leave_amount;
        }
        $s = $settings ?: self::settings((int) $row->agency_id);
        $hpd = (float) $s['hours_per_day'];
        $start = substr((string) $row->start_at, 0, 10);
        $end = substr((string) $row->end_at, 0, 10);
        $allDay = ! isset($row->all_day) || (bool) $row->all_day || empty($row->start_time);
        $aid = (int) $row->agency_id;
        $uid = (int) ($row->user_id ?? 0);
        $cid = ! empty($row->centre_id) ? (int) $row->centre_id : null;
        if (! $allDay) {
            if (in_array($start, self::closedDays($aid, $uid, $cid, $start, $start), true)) {
                return 0.0;
            }
            $h = max(0, (strtotime('2000-01-01 ' . substr((string) $row->end_time, 0, 8)) - strtotime('2000-01-01 ' . substr((string) $row->start_time, 0, 8))) / 3600);
            return round($type->unit === 'hours' ? $h : $h / $hpd, 2);
        }
        $days = count(self::workDays($aid, $uid, $cid, $start, $end));

        return round($type->unit === 'hours' ? $days * $hpd : $days, 2);
    }

    public static function weekdays(string $start, string $end): int
    {
        if ($end < $start) {
            return 0;
        }
        $d = Carbon::parse($start);
        $e = Carbon::parse($end);
        $n = 0;
        $all = 0;
        for ($i = 0; $d->lte($e) && $i < 800; $d->addDay(), $i++) {
            $all++;
            if (! $d->isWeekend()) {
                $n++;
            }
        }

        return $n ?: $all;
    }

    /** Closure types that are days off for staff too. staff_leave (closed BECAUSE of someone's leave) and pd_day (staff
     *  work) still count as working days. */
    public const OFF_CLOSURES = ['stat_holiday', 'holiday', 'emergency', 'other'];

    /** @var array<string,array> */
    private static array $closedMemo = [];

    /**
     * The days in a range this person's centre is shut for a holiday or closure, from the agency's own centre calendar
     * (centre_closures, where Settings > Closures and the statutory-holiday generator write). The centre is the request's,
     * else the employee file's primary centre, else every open centre they work at; agency-level staff use the days EVERY
     * open centre is shut. Nothing generated here: an agency that keeps no holidays on its calendar gets none skipped.
     *
     * @return string[] Y-m-d
     */
    public static function closedDays(int $agencyId, int $userId, ?int $centreId, string $start, string $end): array
    {
        if ($end < $start || ! Schema::hasTable('centre_closures')) {
            return [];
        }
        $key = $agencyId . ':' . $userId . ':' . $centreId . ':' . $start . ':' . $end;
        if (isset(self::$closedMemo[$key])) {
            return self::$closedMemo[$key];
        }
        $open = Live::centreIds($agencyId);
        $centres = $centreId && in_array($centreId, $open, true) ? [$centreId] : [];
        if (! $centres && $userId) {
            $f = self::file($agencyId, $userId);
            if ($f && $f->primary_centre_id && in_array((int) $f->primary_centre_id, $open, true)) {
                $centres = [(int) $f->primary_centre_id];
            }
        }
        if (! $centres && $userId) {
            $centres = DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)->whereIn('role', self::STAFF_ROLES)
                ->whereIn('centre_id', $open ?: [0])->pluck('centre_id')->map(fn ($c) => (int) $c)->unique()->values()->all();
        }
        if (! $centres) {
            $centres = $open;
        }
        if (! $centres) {
            return self::$closedMemo[$key] = [];
        }
        $hits = [];
        $rows = DB::table('centre_closures')->whereIn('centre_id', $centres)->whereIn('closure_type', self::OFF_CLOSURES)
            ->whereDate('closure_date', '<=', $end)->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $start))
            ->get(['centre_id', 'closure_date', 'end_date']);
        foreach ($rows as $r) {
            $a = max($start, substr((string) $r->closure_date, 0, 10));
            $b = min($end, substr((string) ($r->end_date ?: $r->closure_date), 0, 10));
            for ($d = Carbon::parse($a), $i = 0; $d->toDateString() <= $b && $i < 800; $d->addDay(), $i++) {
                $hits[$d->toDateString()][(int) $r->centre_id] = true;
            }
        }
        $out = [];
        foreach ($hits as $day => $cs) {
            if (count($cs) >= count($centres)) {
                $out[] = $day;
            }
        }
        sort($out);

        return self::$closedMemo[$key] = $out;
    }

    /**
     * The days a request actually uses: weekdays that are not holidays/closures. A request with no weekday in it at all
     * (weekend work) uses its calendar days, still less closures.
     *
     * @return string[] Y-m-d
     */
    public static function workDays(int $agencyId, int $userId, ?int $centreId, string $start, string $end): array
    {
        if ($end < $start) {
            return [];
        }
        $closed = array_flip(self::closedDays($agencyId, $userId, $centreId, $start, $end));
        $week = [];
        $all = [];
        for ($d = Carbon::parse($start), $i = 0; $d->toDateString() <= $end && $i < 800; $d->addDay(), $i++) {
            $ymd = $d->toDateString();
            $all[] = $ymd;
            if (! $d->isWeekend()) {
                $week[] = $ymd;
            }
        }
        $use = $week ?: $all;

        return array_values(array_filter($use, fn ($x) => ! isset($closed[$x])));
    }

    /**
     * A lump-sum grant for the leave year starting $ys. Someone hired part-way through that year gets the share of
     * the days left from their start date (Anthony, 2026-11-26), rounded to the nearest half day, or quarter hour for
     * an hour-based type.
     *
     * @return array{0:?float,1:bool} [amount, prorated]
     */
    public static function grantAmount(object $type, ?string $employmentType, ?string $hire, string $ys): array
    {
        $ent = self::entitlement($type, $employmentType, self::serviceYears($hire, $ys));
        if (! $ent || ! $hire || $hire <= $ys) {
            return [$ent, false];
        }
        $ye = self::addYears($ys, 1);
        if ($hire >= $ye) {
            return [null, false];
        }
        $total = max(1, (int) round((strtotime($ye) - strtotime($ys)) / 86400));
        $left = max(0, (int) round((strtotime($ye) - strtotime($hire)) / 86400));
        $step = ($type->unit ?? 'days') === 'hours' ? 0.25 : 0.5;

        return [round(($ent * $left / $total) / $step) * $step, true];
    }

    /**
     * Make the ledger agree with a request: approved = minus its amount, anything else = nothing. Writes only the
     * difference (a "taken" or a "returned" row), so it can be called after every change and never double-counts.
     */
    public static function syncRequest(object $row, ?int $actorId = null): void
    {
        if (! self::on((int) $row->agency_id)) {
            return;
        }
        $type = self::typeForRequest($row);
        $target = [];
        if ($type && $row->status === 'approved') {
            $target[(int) $type->id] = -self::amountFor($row, $type);
        }
        $current = DB::table('hr_leave_ledger')->where('time_off_request_id', $row->id)
            ->groupBy('leave_type_id')->selectRaw('leave_type_id, SUM(amount) s')->pluck('s', 'leave_type_id')->all();
        $today = AgencyTime::today((int) $row->agency_id);
        foreach (array_unique(array_merge(array_keys($target), array_keys($current))) as $tid) {
            $diff = round(($target[$tid] ?? 0) - (float) ($current[$tid] ?? 0), 2);
            if (abs($diff) < 0.005) {
                continue;
            }
            DB::table('hr_leave_ledger')->insert([
                'agency_id' => (int) $row->agency_id, 'user_id' => (int) $row->user_id, 'leave_type_id' => (int) $tid,
                'entry_date' => $today, 'amount' => $diff, 'kind' => $diff < 0 ? 'taken' : 'returned',
                'time_off_request_id' => (int) $row->id,
                'reason' => ($diff < 0 ? 'Time off approved' : ($row->status === 'cancelled' ? 'Time off withdrawn' : 'Time off no longer approved')),
                'created_by_id' => $actorId, 'created_at' => now(),
            ]);
        }
        // An old request (no leave_type_id) is never written to: its type and amount are worked out each time.
    }

    /** [type name, "Vacation: 8 days left, 5 after this request"] for a request, or [null, null] without HR. */
    public static function emailLine(object $r): array
    {
        try {
            if (! self::on((int) $r->agency_id) || ! ($t = self::typeForRequest($r))) {
                return [null, null];
            }
            $amt = self::amountFor($r, $t);
            $bal = self::balance((int) $r->user_id, (int) $t->id);
            $f = fn ($v) => rtrim(rtrim(number_format($v, 2), '0'), '.');
            if ($t->accrual === 'none' && ! self::hasRows((int) $r->user_id, (int) $t->id)) {
                return [$t->name, $t->name . ': ' . $f($amt) . ' ' . $t->unit . ' (not tracked)'];
            }

            return [$t->name, $t->name . ': ' . $f($bal) . ' ' . $t->unit . ' left, ' . $f($bal - $amt) . ' after this request'];
        } catch (\Throwable $e) {
            return [null, null];
        }
    }

    // ── Accruals (the nightly job, and "Run accruals now") ───────────────────────────

    /**
     * Grant / accrue / carry over / expire for every staff member and every type of one agency, up to the agency's
     * today. Every row carries a source_key that is unique per person and type, so a rerun adds nothing.
     *
     * @return array{people:int, rows:int}
     */
    public static function accrueAgency(int $agencyId, bool $dry = false, ?int $onlyUser = null): array
    {
        $types = self::types($agencyId)->filter(fn ($t) => $t->accrual !== 'none' || $t->carry_mode !== 'all');
        if ($types->isEmpty()) {
            return ['people' => 0, 'rows' => 0];
        }
        $s = self::settings($agencyId);
        $today = AgencyTime::today($agencyId);
        $ids = $onlyUser ? [$onlyUser] : self::staffIds($agencyId);
        $rows = 0;
        foreach ($ids as $uid) {
            $u = DB::table('users')->where('id', $uid)->first(['id', 'hire_date']);
            $file = self::file($agencyId, $uid);
            if (! $u || ($file && $file->status === 'terminated')) {
                continue;
            }
            foreach ($types as $t) {
                $rows += self::accrueOne($agencyId, $u, $file, $t, $today, $s, $dry);
            }
        }

        return ['people' => count($ids), 'rows' => $rows];
    }

    private static function accrueOne(int $agencyId, object $u, ?object $file, object $t, string $today, array $s, bool $dry): int
    {
        $hire = $u->hire_date ? substr((string) $u->hire_date, 0, 10) : null;
        if ($hire && $hire > $today) {
            return 0;
        }
        $ys = self::yearStart($agencyId, (int) $u->id, $today, $s, $hire);
        $n = 0;
        $put = function (string $date, float $amt, string $kind, string $key, string $reason) use ($agencyId, $u, $t, $dry, &$n) {
            $amt = round($amt, 2);
            if (abs($amt) < 0.005) {
                return;
            }
            if (DB::table('hr_leave_ledger')->where('leave_type_id', $t->id)->where('user_id', $u->id)->where('source_key', $key)->exists()) {
                return;
            }
            $n++;
            if ($dry) {
                return;
            }
            DB::table('hr_leave_ledger')->insertOrIgnore([
                'agency_id' => $agencyId, 'user_id' => (int) $u->id, 'leave_type_id' => (int) $t->id, 'entry_date' => $date,
                'amount' => $amt, 'kind' => $kind, 'source_key' => $key, 'reason' => $reason, 'created_at' => now(),
            ]);
        };

        // 1. The year that ended: what carries over. Only when there was a balance before this year began.
        $before = (float) DB::table('hr_leave_ledger')->where('user_id', $u->id)->where('leave_type_id', $t->id)->where('entry_date', '<', $ys)->sum('amount');
        $carried = 0.0;
        if ($before > 0.005) {
            $keep = $t->carry_mode === 'all' ? $before : ($t->carry_mode === 'capped' ? min($before, (float) $t->carry_cap) : 0.0);
            $put($ys, -($before - $keep), 'carry_forfeit', 'carry:' . $ys, $keep > 0 ? 'Over the carry-over limit at year end' : 'Does not carry over to the new leave year');
            $carried = $keep;
            // 2. Carried days that expire part-way into the new year, if they were not used first.
            if ($carried > 0 && $t->carry_expiry_months) {
                $exp = Carbon::parse($ys)->addMonthsNoOverflow((int) $t->carry_expiry_months)->toDateString();
                if ($today >= $exp) {
                    $used = -(float) DB::table('hr_leave_ledger')->where('user_id', $u->id)->where('leave_type_id', $t->id)
                        ->where('entry_date', '>=', $ys)->where('entry_date', '<', $exp)->whereIn('kind', ['taken', 'returned'])->sum('amount');
                    $left = max(0.0, $carried - max(0.0, $used));
                    $left = min($left, max(0.0, self::balance((int) $u->id, (int) $t->id)));
                    $put($exp, -$left, 'expiry', 'expire:' . $ys, 'Carried-over balance expired');
                }
            }
        }

        // 3. This year's entitlement.
        $etype = $file->employment_type ?? null;
        $years = self::serviceYears($hire, $ys);
        $ent = self::entitlement($t, $etype, $years);
        $label = 'Leave year from ' . Carbon::parse($ys)->format('M j, Y');
        if ($t->accrual === 'lump' && $ent) {
            [$amt, $pro] = self::grantAmount($t, $etype, $hire, $ys);
            if ($amt) {
                $put(max($ys, $hire ?: $ys), $amt, 'grant', 'grant:' . $ys, $label . ($pro ? ' (prorated from the start date)' : ''));
            }
        } elseif ($t->accrual === 'pay_period' && $ent && in_array((int) $t->periods_per_year, self::PERIODS, true)) {
            $N = (int) $t->periods_per_year;
            for ($k = 0; $k < $N; $k++) {
                $p = self::periodStart($ys, $N, $k);
                if ($p > $today) {
                    break;
                }
                if ($hire && $p < $hire) {
                    continue;
                }
                $put($p, $ent / $N, 'accrual', 'pp:' . $ys . ':' . $k, 'Pay period ' . ($k + 1) . ' of ' . $N);
            }
        } elseif ($t->accrual === 'hours_worked' && (float) $t->hours_per_unit > 0) {
            $cap = $ent;   // null = no yearly limit
            $earned = (float) DB::table('hr_leave_ledger')->where('user_id', $u->id)->where('leave_type_id', $t->id)
                ->where('kind', 'accrual')->where('source_key', 'like', 'hw:%')->where('entry_date', '>=', $ys)->sum('amount');
            $w = Carbon::parse(max($ys, $hire ?: $ys))->startOfWeek(Carbon::MONDAY);
            $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
            for ($i = 0; $i < 60; $i++, $w->addWeek()) {
                $weekEnd = $w->copy()->addDays(6)->toDateString();
                if ($weekEnd >= $today) {
                    break;
                }
                $wk = $w->toDateString();
                if ($wk < $ys) {
                    continue;
                }
                [$from, $to] = AgencyTime::spanRange($agencyId, $wk, $weekEnd);
                $mins = (float) DB::table('time_entries')->where('user_id', $u->id)->whereIn('centre_id', $centres ?: [0])
                    ->whereNotNull('clocked_out_at')->where('clocked_in_at', '>=', $from)->where('clocked_in_at', '<', $to)
                    ->selectRaw('COALESCE(SUM(GREATEST(TIMESTAMPDIFF(MINUTE, clocked_in_at, clocked_out_at) - COALESCE(total_break_min,0), 0)),0) m')->value('m');
                $amt = ($mins / 60) / (float) $t->hours_per_unit;
                if ($cap !== null) {
                    $amt = min($amt, max(0, $cap - $earned));
                }
                if ($amt < 0.005) {
                    continue;
                }
                $before = $n;
                $put($weekEnd, $amt, 'accrual', 'hw:' . $wk, 'Hours worked, week of ' . $w->format('M j'));
                if ($n > $before) {
                    $earned += round($amt, 2);
                }
            }
        }

        return $n;
    }

    /** Start of pay period $k (0-based) of a leave year with N periods. */
    public static function periodStart(string $ys, int $N, int $k): string
    {
        $c = Carbon::parse($ys);
        if ($N === 12) {
            return $c->addMonthsNoOverflow($k)->toDateString();
        }
        if ($N === 24) {
            $m = $c->copy()->addMonthsNoOverflow(intdiv($k, 2));
            return ($k % 2 ? $m->addDays(15) : $m)->toDateString();
        }

        return $c->addDays($k * ($N === 52 ? 7 : 14))->toDateString();
    }

    // ── Absences ──────────────────────────────────────────────────────────────────────

    /**
     * Approved leave overlapping [from, to], one entry per weekday (all of a weekend-only request's days), with the
     * day's share in days and hours. Scope: null = whole agency; else these people only.
     *
     * @param int[]|null $people
     */
    public static function absenceDays(int $agencyId, string $from, string $to, ?array $people): array
    {
        $s = self::settings($agencyId);
        $hpd = (float) $s['hours_per_day'];
        $q = DB::table('time_off_requests')->where('agency_id', $agencyId)->where('status', 'approved')
            ->whereDate('start_at', '<=', $to)->whereDate('end_at', '>=', $from);
        if ($people !== null) {
            $q->whereIn('user_id', $people ?: [0]);
        }
        $out = [];
        foreach ($q->get() as $r) {
            $type = self::typeForRequest($r);
            $start = substr((string) $r->start_at, 0, 10);
            $end = substr((string) $r->end_at, 0, 10);
            $allDay = (bool) $r->all_day || empty($r->start_time);
            $hours = $allDay ? $hpd : max(0, (strtotime('2000-01-01 ' . $r->end_time) - strtotime('2000-01-01 ' . $r->start_time)) / 3600);
            $use = $allDay ? self::workDays($agencyId, (int) $r->user_id, $r->centre_id ? (int) $r->centre_id : null, $start, $end)
                : array_diff([$start], self::closedDays($agencyId, (int) $r->user_id, $r->centre_id ? (int) $r->centre_id : null, $start, $start));
            $len = count($use);
            foreach ($use as $day) {
                if ($day < $from || $day > $to) {
                    continue;
                }
                $out[] = ['request_id' => (int) $r->id, 'user_id' => (int) $r->user_id, 'date' => $day, 'centre_id' => $r->centre_id ? (int) $r->centre_id : null,
                    'leave_type_id' => $type ? (int) $type->id : null, 'type_name' => $type ? $type->name : (self::REQUEST_TYPES[$r->request_type] ?? ucfirst((string) $r->request_type)),
                    'colour' => $type->colour ?? '#64748B', 'days' => $allDay ? 1.0 : round($hours / $hpd, 2), 'hours' => round($hours, 2), 'request_days' => $allDay ? $len : round($hours / $hpd, 2)];
            }
        }

        return $out;
    }
}
