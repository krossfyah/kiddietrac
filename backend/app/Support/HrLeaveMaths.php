<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KIDDIETRAC HR PHASE 4, BATCH 9 (2026-11-27): LEAVE MATHS EXTRAS. Four things, all on top of HrLeave's ledger
 * (balance = SUM of hr_leave_ledger rows; a row is never edited; every change is a new row with a reason):
 *
 *  1. PROJECTED BALANCE: what is left after EVERY pending request of that type, shown next to "after this one" on the
 *     approval screens (TimeOffController::withLeave adds leave_pending_all + leave_balance_projected). Read only.
 *
 *  2. SHUTDOWNS: a centre closure (holiday / emergency / other; never a statutory holiday) can take vacation off the
 *     staff it closes, when an agency admin chooses "Deduct" for that closure. Nothing is ever deducted by itself, a
 *     past closure included: the admin sees a preview of who loses how much, can leave people out, and applies it.
 *     "Don't deduct" is recorded too, so the choice is visible. Deducting writes one ledger row per person
 *     (kind shutdown, source_key shut:<id>:<round>); reversing writes the opposite rows (shut-undo:<id>:<round>).
 *     A day already taken by another applied shutdown of the same type, a statutory holiday, a weekend, a day before
 *     someone started, or a day their centre is not shut (staff at two centres) is never counted.
 *
 *  3. RE-CHECK WHEN CLOSURES CHANGE: adding, moving or removing a closure changes how many working days a booked
 *     request covers. The amounts already booked are NOT changed: each request (and each applied shutdown) whose
 *     amount would now come out differently gets an open row in hr_leave_rechecks ("amount may be wrong - review").
 *     A manager (never the person themselves) then corrects it (one new ledger row for the difference) or keeps it.
 *
 *  4. PART-TIME PRO-RATA: with the agency setting on, a NEW lump grant or pay-period accrual for someone part-time
 *     (their file's hours a week below the company's full-time week) is that share of the entitlement. Grants already
 *     in the ledger are never touched (each grant has its own source_key, so the nightly job never rewrites one).
 *     Off by default: until an admin turns it on, accrual is exactly what it was.
 *
 * Settings: agencies.settings.hr_leave_maths (never inside settings.hr: HrLeave::saveSettings rewrites that block).
 */
final class HrLeaveMaths
{
    public const KEY = 'hr_leave_maths';
    public const DEFAULTS = ['pro_rata' => false, 'full_time_hours' => 40.0, 'type_ids' => [], 'employment_types' => ['part_time', 'casual', 'student']];
    /** Closure types a shutdown deduction may use. These are already days off in HrLeave::OFF_CLOSURES (so a leave request
     *  never counts them, and a deduction cannot count a day twice); a statutory holiday is never deducted. */
    public const SHUTDOWN_TYPES = ['holiday', 'emergency', 'other'];
    public const KIND = 'shutdown';

    public static function ready(): bool
    {
        static $r = null;

        return $r ??= rescue(fn () => Schema::hasTable('hr_shutdowns') && Schema::hasTable('hr_leave_rechecks'), false, false);
    }

    // ── settings ─────────────────────────────────────────────────────────────────────

    /** @var array<int,array{0:int,1:array}> */
    private static array $memo = [];

    public static function settings(int $agencyId, bool $fresh = false): array
    {
        if (! $fresh && isset(self::$memo[$agencyId]) && self::$memo[$agencyId][0] > time() - 60) {
            return self::$memo[$agencyId][1];
        }
        $raw = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];
        $m = is_array($raw[self::KEY] ?? null) ? $raw[self::KEY] : [];
        $out = self::DEFAULTS;
        $out['pro_rata'] = (bool) ($m['pro_rata'] ?? false);
        $out['full_time_hours'] = max(1.0, min(80.0, (float) ($m['full_time_hours'] ?? 40)));
        $out['type_ids'] = array_values(array_unique(array_map('intval', is_array($m['type_ids'] ?? null) ? $m['type_ids'] : [])));
        $et = is_array($m['employment_types'] ?? null) ? array_values(array_intersect($m['employment_types'], array_keys(HrLeave::EMPLOYMENT))) : self::DEFAULTS['employment_types'];
        $out['employment_types'] = array_values(array_diff($et, ['full_time', 'contract']));
        self::$memo[$agencyId] = [time(), $out];

        return $out;
    }

    public static function saveSettings(int $agencyId, array $s): array
    {
        DB::transaction(function () use ($agencyId, $s) {
            $row = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->first(['settings']);
            $all = json_decode((string) ($row->settings ?? ''), true) ?: [];
            $all[self::KEY] = ['pro_rata' => (bool) $s['pro_rata'], 'full_time_hours' => (float) $s['full_time_hours'],
                'type_ids' => array_values(array_map('intval', $s['type_ids'])), 'employment_types' => array_values($s['employment_types'])];
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        });
        unset(self::$memo[$agencyId]);

        return self::settings($agencyId, true);
    }

    // ── 4. part-time pro-rata ───────────────────────────────────────────────────────────

    /**
     * The share of a full entitlement a NEW grant gets for this person and type, or null for the full amount (setting
     * off, a type not chosen, not a part-time employment type, or no hours a week on their file - never guessed).
     *
     * @return array{0:float,1:string}|null [factor 0-1, the words added to the ledger reason]
     */
    public static function proRata(int $agencyId, ?object $file, object $type, ?array $s = null): ?array
    {
        $s = $s ?: self::settings($agencyId);
        if (! $s['pro_rata'] || ! $file || ! in_array((int) $type->id, $s['type_ids'], true)) {
            return null;
        }
        if (! in_array((string) ($file->employment_type ?? ''), $s['employment_types'], true)) {
            return null;
        }
        $hpw = isset($file->hours_per_week) ? (float) $file->hours_per_week : 0.0;
        $full = (float) $s['full_time_hours'];
        if ($hpw <= 0 || $hpw >= $full) {
            return null;
        }
        $f = fn ($v) => rtrim(rtrim(number_format($v, 2), '0'), '.');

        return [round($hpw / $full, 4), 'part-time: ' . $f($hpw) . ' of ' . $f($full) . ' hours a week'];
    }

    /** What pro-rata would do to each person's yearly entitlement (for the settings preview). Reads only. */
    public static function proRataPreview(int $agencyId, array $s): array
    {
        $types = HrLeave::types($agencyId)->filter(fn ($t) => in_array((int) $t->id, $s['type_ids'], true) && in_array($t->accrual, ['lump', 'pay_period'], true))->values();
        $ids = array_values(array_diff(HrLeave::staffIds($agencyId), HrLeaving::leaverIds($agencyId)));
        $files = DB::table('hr_employee_files')->where('agency_id', $agencyId)->whereIn('user_id', $ids ?: [0])->get()->keyBy('user_id');
        $users = DB::table('users')->whereIn('id', $ids ?: [0])->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'hire_date']);
        $today = AgencyTime::today($agencyId);
        $hs = HrLeave::settings($agencyId);
        $rows = [];
        $missing = 0;
        foreach ($users as $u) {
            $file = $files[$u->id] ?? null;
            if (! $file || ($file->status ?? '') === 'terminated' || ! in_array((string) $file->employment_type, $s['employment_types'], true)) {
                continue;
            }
            $hpw = $file->hours_per_week !== null ? (float) $file->hours_per_week : null;
            $lines = [];
            foreach ($types as $t) {
                $hire = $u->hire_date ? substr((string) $u->hire_date, 0, 10) : null;
                $ent = HrLeave::entitlement($t, $file->employment_type, HrLeave::serviceYears($hire, HrLeave::yearStart($agencyId, (int) $u->id, $today, $hs, $hire)));
                if (! $ent) continue;
                $pr = self::proRata($agencyId, $file, $t, $s);
                $step = $t->unit === 'hours' ? 0.25 : 0.5;
                $lines[] = ['type' => $t->name, 'unit' => $t->unit, 'full' => $ent, 'pro_rata' => $pr ? round(($ent * $pr[0]) / $step) * $step : $ent];
            }
            if (! $lines) continue;
            if ($hpw === null) $missing++;
            $rows[] = ['user_id' => (int) $u->id, 'name' => trim($u->first_name . ' ' . $u->last_name), 'employment_type' => $file->employment_type,
                'hours_per_week' => $hpw, 'lines' => $lines];
        }

        return ['rows' => $rows, 'missing_hours' => $missing, 'types' => $types->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name])->all()];
    }

    // ── 3. re-check booked leave ─────────────────────────────────────────────────────────

    /** The amount a request would come out at today, ignoring the amount stored on it. */
    public static function freshAmount(object $r, object $t): float
    {
        $c = clone $r;
        $c->leave_amount = null;

        return HrLeave::amountFor($c, $t);
    }

    /** The amount booked for a request: approved = what the ledger took; pending = the amount stored on it. Null = nothing booked. */
    public static function bookedAmount(object $r, object $t): ?float
    {
        if ($r->status === 'approved') {
            $q = DB::table('hr_leave_ledger')->where('time_off_request_id', $r->id)->where('leave_type_id', $t->id);
            if (! (clone $q)->exists()) {
                return null;   // approved before HR counted it, never booked: nothing to be wrong
            }

            return round(-(float) $q->sum('amount'), 2);
        }
        if ($r->status === 'pending' && $r->leave_amount !== null && (int) ($r->leave_type_id ?? 0) === (int) $t->id) {
            return round((float) $r->leave_amount, 2);
        }

        return null;
    }

    /**
     * Flag (or clear) one request. Returns 1 when a NEW open flag was made. Never changes the request or the ledger.
     */
    public static function recheckRequest(object $r, string $cause, ?int $closureId = null): int
    {
        $open = DB::table('hr_leave_rechecks')->where('time_off_request_id', $r->id)->where('status', 'open')->first();
        $t = in_array($r->status, ['approved', 'pending'], true) ? HrLeave::typeForRequest($r) : null;
        $booked = $t ? self::bookedAmount($r, $t) : null;
        $fresh = ($t && $booked !== null) ? self::freshAmount($r, $t) : null;
        if ($booked === null || abs($fresh - $booked) < 0.005) {
            if ($open) {
                DB::table('hr_leave_rechecks')->where('id', $open->id)->update(['status' => 'cleared', 'resolved_at' => now(), 'updated_at' => now(),
                    'note' => 'The amount matches again']);
            }

            return 0;
        }
        if ($open) {
            DB::table('hr_leave_rechecks')->where('id', $open->id)->update(['booked' => $booked, 'fresh' => $fresh, 'cause' => mb_substr($cause, 0, 255),
                'closure_id' => $closureId, 'updated_at' => now()]);

            return 0;
        }
        DB::table('hr_leave_rechecks')->insert(['agency_id' => (int) $r->agency_id, 'time_off_request_id' => (int) $r->id, 'shutdown_id' => null,
            'user_id' => (int) $r->user_id, 'leave_type_id' => (int) $t->id, 'booked' => $booked, 'fresh' => $fresh, 'unit' => $t->unit,
            'cause' => mb_substr($cause, 0, 255), 'closure_id' => $closureId, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);

        return 1;
    }

    /**
     * A closure was added, moved or removed (OperationsV2Controller, HolidaySyncCommand). Re-check the booked leave
     * and applied shutdowns that touch any of the date ranges. Flags only; nothing is re-deducted.
     *
     * @param array<int,array{0:string,1:string}> $ranges [from, to] Y-m-d (old and new dates)
     */
    public static function closureChanged(int $centreId, array $ranges, ?int $closureId, string $cause, ?int $actorId = null): int
    {
        $aid = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
        if (! $aid || ! self::ready() || ! HrLeave::on($aid)) {
            return 0;
        }
        HrLeave::forgetClosed();
        $new = 0;
        $seen = [];
        foreach ($ranges as [$a, $b]) {
            $a = substr((string) $a, 0, 10);
            $b = substr((string) ($b ?: $a), 0, 10);
            $rows = DB::table('time_off_requests')->where('agency_id', $aid)->whereIn('status', ['approved', 'pending'])
                ->whereDate('start_at', '<=', $b)->whereDate('end_at', '>=', $a)->get();
            foreach ($rows as $r) {
                if (isset($seen[$r->id])) continue;
                $seen[$r->id] = true;
                $new += self::recheckRequest($r, $cause, $closureId);
            }
            foreach (DB::table('hr_shutdowns')->where('agency_id', $aid)->where('status', 'applied')
                ->where(fn ($q) => $q->where('closure_id', $closureId ?: 0)->orWhere(fn ($w) => $w->where('closure_from', '<=', $b)->where('closure_to', '>=', $a)))->get() as $sd) {
                if (isset($seen['s' . $sd->id])) continue;
                $seen['s' . $sd->id] = true;
                $new += self::recheckShutdown($sd, $cause);
            }
        }
        if ($new) {
            HrOpenShifts::bellManagers($aid, $centreId, array_filter([$actorId]), $new === 1 ? 'A booked time-off amount may be wrong' : $new . ' booked time-off amounts may be wrong',
                $cause . '. Nothing was changed: review them under Time off > Closures & checks.', '#time-off?tab=leavemaths');
        }

        return $new;
    }

    /** "Check booked leave now": every approved or pending request ending in the last year or later. */
    public static function scan(int $agencyId): array
    {
        HrLeave::forgetClosed();
        $from = Carbon::parse(AgencyTime::today($agencyId))->subYear()->toDateString();
        $new = 0;
        $checked = 0;
        foreach (DB::table('time_off_requests')->where('agency_id', $agencyId)->whereIn('status', ['approved', 'pending'])->whereDate('end_at', '>=', $from)->get() as $r) {
            $checked++;
            $new += self::recheckRequest($r, 'Checked by hand');
        }
        foreach (DB::table('hr_shutdowns')->where('agency_id', $agencyId)->where('status', 'applied')->get() as $sd) {
            $checked++;
            $new += self::recheckShutdown($sd, 'Checked by hand');
        }

        return ['checked' => $checked, 'flagged' => $new, 'open' => DB::table('hr_leave_rechecks')->where('agency_id', $agencyId)->where('status', 'open')->count()];
    }

    // ── 2. shutdowns ────────────────────────────────────────────────────────────────────

    public static function closureRange(object $c): array
    {
        $a = substr((string) $c->closure_date, 0, 10);

        return [$a, substr((string) ($c->end_date ?: $a), 0, 10)];
    }

    public static function rangeLabel(string $a, string $b): string
    {
        $fa = Carbon::parse($a)->format('M j, Y');

        return $a === $b ? $fa : (substr($a, 0, 4) === substr($b, 0, 4) ? Carbon::parse($a)->format('M j') : $fa) . ' – ' . Carbon::parse($b)->format('M j, Y');
    }

    /** The default type a shutdown takes from: the vacation-mapped type, else the first lump/pay-period one. */
    public static function defaultType(int $agencyId): ?object
    {
        $types = HrLeave::types($agencyId);

        return $types->firstWhere('maps_request_type', 'vacation') ?: $types->first(fn ($t) => in_array($t->accrual, ['lump', 'pay_period'], true));
    }

    /** Days (per person, per type) already taken by OTHER applied shutdowns, net of reversals. */
    private static function daysTaken(int $agencyId, int $typeId, ?int $exceptShutdown): array
    {
        $out = [];
        $q = DB::table('hr_shutdown_lines as l')->join('hr_shutdowns as s', 's.id', '=', 'l.shutdown_id')
            ->where('s.agency_id', $agencyId)->where('s.status', 'applied')->where('l.leave_type_id', $typeId)
            ->when($exceptShutdown, fn ($w) => $w->where('s.id', '!=', $exceptShutdown))->where('l.current', 1)->get(['l.user_id', 'l.days']);
        foreach ($q as $l) {
            foreach (json_decode((string) $l->days, true) ?: [] as $d) $out[(int) $l->user_id][$d] = true;
        }

        return $out;
    }

    /**
     * Who a shutdown would take how much from. $closure = the centre_closures row (or the shutdown's own snapshot when the
     * closure was deleted: then nobody loses anything). Reads only.
     */
    public static function preview(int $agencyId, ?object $closure, object $type, array $exclude = [], ?int $shutdownId = null): array
    {
        $hs = HrLeave::settings($agencyId);
        $hpd = (float) $hs['hours_per_day'];
        $per = $type->unit === 'hours' ? $hpd : 1.0;
        $rows = [];
        $total = 0.0;
        if (! $closure) {
            return ['rows' => [], 'total' => 0.0, 'people' => 0, 'per_day' => $per];
        }
        [$a, $b] = self::closureRange($closure);
        $cid = (int) $closure->centre_id;
        $leavers = HrLeaving::leaverIds($agencyId);
        $ids = array_values(array_diff(HrLeave::staffIds($agencyId), $leavers));
        $files = DB::table('hr_employee_files')->where('agency_id', $agencyId)->whereIn('user_id', $ids ?: [0])->get()->keyBy('user_id');
        $users = DB::table('users')->whereIn('id', $ids ?: [0])->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'hire_date']);
        $taken = self::daysTaken($agencyId, (int) $type->id, $shutdownId);
        $tracked = $type->accrual !== 'none';
        $hasRows = DB::table('hr_leave_ledger')->where('leave_type_id', $type->id)->whereIn('user_id', $ids ?: [0])->distinct()->pluck('user_id')->map(fn ($v) => (int) $v)->flip()->all();
        // People whose booked time off over these dates is waiting for review (a closure changed): review that first.
        $toReview = DB::table('hr_leave_rechecks as rc')->join('time_off_requests as r', 'r.id', '=', 'rc.time_off_request_id')->where('rc.agency_id', $agencyId)
            ->where('rc.status', 'open')->whereDate('r.start_at', '<=', $b)->whereDate('r.end_at', '>=', $a)->pluck('rc.user_id')->map(fn ($v) => (int) $v)->flip()->all();
        $stat = DB::table('centre_closures')->where('closure_type', 'stat_holiday')->whereDate('closure_date', '<=', $b)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $a))->get(['centre_id', 'closure_date', 'end_date']);
        foreach ($users as $u) {
            $uid = (int) $u->id;
            $file = $files[$uid] ?? null;
            $name = trim($u->first_name . ' ' . $u->last_name);
            $skip = null;
            if ($file && $file->status === 'terminated') $skip = 'Has left';
            elseif ($file && $file->employment_type === 'contract') $skip = 'Contractor';
            $centres = HrLeave::centresFor($agencyId, $uid, null);
            if (! in_array($cid, $centres, true)) continue;   // does not work at this centre: not listed at all
            if (! $skip && ! $tracked && ! isset($hasRows[$uid])) $skip = 'This leave type is not tracked for them';
            $hire = $u->hire_date ? substr((string) $u->hire_date, 0, 10) : null;
            $days = [];
            if (! $skip) {
                $closed = array_flip(HrLeave::closedDays($agencyId, $uid, null, $a, $b));
                $statDays = [];
                foreach ($stat as $s) {
                    if (! in_array((int) $s->centre_id, $centres, true)) continue;
                    [$sa, $sb] = self::closureRange($s);
                    for ($d = Carbon::parse(max($a, $sa)), $i = 0; $d->toDateString() <= min($b, $sb) && $i < 400; $d->addDay(), $i++) $statDays[$d->toDateString()] = true;
                }
                for ($d = Carbon::parse($a), $i = 0; $d->toDateString() <= $b && $i < 400; $d->addDay(), $i++) {
                    $ymd = $d->toDateString();
                    if ($d->isWeekend() || ! isset($closed[$ymd]) || isset($statDays[$ymd]) || isset($taken[$uid][$ymd]) || ($hire && $ymd < $hire)) continue;
                    $days[] = $ymd;
                }
                if (! $days) $skip = 'No working days in this closure';
            }
            $amount = round(count($days) * $per, 2);
            $out = in_array($uid, $exclude, true);
            $bal = HrLeave::balance($uid, (int) $type->id);
            $rows[] = ['user_id' => $uid, 'name' => $name, 'days' => $days, 'amount' => $skip ? 0.0 : $amount, 'skip' => $skip, 'left_out' => $out && ! $skip,
                'balance' => $bal, 'after' => round($bal - (($skip || $out) ? 0 : $amount), 2), 'review' => isset($toReview[$uid])];
            if (! $skip && ! $out) $total += $amount;
        }

        return ['rows' => $rows, 'total' => round($total, 2), 'people' => count(array_filter($rows, fn ($r) => ! $r['skip'] && ! $r['left_out'] && $r['amount'] > 0)),
            'per_day' => $per, 'from' => $a, 'to' => $b];
    }

    /** Net amount each person has had taken by one shutdown (all rounds, reversals included). */
    public static function netByUser(int $shutdownId): array
    {
        return DB::table('hr_leave_ledger')->where('kind', self::KIND)->where(fn ($q) => $q->where('source_key', 'like', 'shut:' . $shutdownId . ':%')->orWhere('source_key', 'like', 'shut-undo:' . $shutdownId . ':%'))
            ->groupBy('user_id')->selectRaw('user_id, SUM(amount) s')->pluck('s', 'user_id')->map(fn ($v) => round(-(float) $v, 2))->all();
    }

    /**
     * Write the rows that make each person's shutdown total equal the preview: the first apply takes the lot, a
     * re-apply after the dates changed writes only the difference. Inside the caller's transaction.
     *
     * @return array{people:int, total:float}
     */
    public static function writeLines(object $sd, array $preview, object $type, int $actorId, string $reason): array
    {
        $round = (int) $sd->round + 1;
        $net = self::netByUser((int) $sd->id);
        $want = [];
        foreach ($preview['rows'] as $r) {
            if (! $r['skip'] && ! $r['left_out'] && $r['amount'] > 0) $want[$r['user_id']] = $r;
        }
        $people = 0;
        $total = 0.0;
        $entry = $preview['from'] ?? AgencyTime::today((int) $sd->agency_id);
        DB::table('hr_shutdown_lines')->where('shutdown_id', $sd->id)->update(['current' => 0]);
        $bells = [];
        foreach (array_unique(array_merge(array_keys($want), array_keys($net))) as $uid) {
            $target = (float) ($want[$uid]['amount'] ?? 0);
            $diff = round($target - (float) ($net[$uid] ?? 0), 2);
            $lineId = DB::table('hr_shutdown_lines')->insertGetId(['shutdown_id' => $sd->id, 'user_id' => $uid, 'leave_type_id' => $type->id, 'round' => $round,
                'days' => json_encode($want[$uid]['days'] ?? []), 'amount' => $target, 'current' => 1, 'created_at' => now()]);
            if (abs($diff) < 0.005) continue;
            DB::table('hr_leave_ledger')->insert(['agency_id' => (int) $sd->agency_id, 'user_id' => $uid, 'leave_type_id' => (int) $type->id,
                'entry_date' => ($want[$uid]['days'][0] ?? $entry), 'amount' => -$diff, 'kind' => self::KIND, 'source_key' => 'shut:' . $sd->id . ':' . $round,
                'reason' => mb_substr($reason, 0, 500), 'created_by_id' => $actorId, 'created_at' => now()]);
            DB::table('hr_shutdown_lines')->where('id', $lineId)->update(['ledger_amount' => -$diff]);
            $people++;
            $total += $diff;
            if ($type->visible_to_staff) $bells[] = [$uid, $diff];
        }
        DB::table('hr_shutdowns')->where('id', $sd->id)->update(['round' => $round, 'amount_total' => round(array_sum(array_map(fn ($r) => $r['amount'], $want)), 2),
            'people_count' => count($want), 'updated_at' => now()]);
        $f = fn ($v) => rtrim(rtrim(number_format(abs($v), 2), '0'), '.');
        $rows = [];
        foreach ($bells as [$uid, $diff]) {
            if ($uid === $actorId) continue;
            $rows[] = ['user_id' => $uid, 'agency_id' => (int) $sd->agency_id, 'type' => 'time_off',
                'title' => 'Your ' . $type->name . ' balance was ' . ($diff > 0 ? 'reduced' : 'increased') . ' by ' . $f($diff) . ' ' . $type->unit,
                'body' => $reason, 'data' => json_encode(['link' => '#time-off']), 'created_at' => now()];
        }
        try { if ($rows) Notify::write($rows); } catch (\Throwable $e) { report($e); }

        return ['people' => $people, 'total' => round($total, 2)];
    }

    /** Re-check one applied shutdown against its closure as it is now. Returns 1 when a NEW open flag was made. */
    public static function recheckShutdown(object $sd, string $cause): int
    {
        $open = DB::table('hr_leave_rechecks')->where('shutdown_id', $sd->id)->where('status', 'open')->first();
        $type = DB::table('hr_leave_types')->where('id', $sd->leave_type_id)->first();
        if (! $type) return 0;
        $closure = $sd->closure_id ? DB::table('centre_closures')->where('id', $sd->closure_id)->first() : null;
        $p = self::preview((int) $sd->agency_id, $closure, $type, json_decode((string) $sd->excluded_user_ids, true) ?: [], (int) $sd->id);
        $want = [];
        foreach ($p['rows'] as $r) if (! $r['skip'] && ! $r['left_out'] && $r['amount'] > 0) $want[$r['user_id']] = $r['amount'];
        $net = array_filter(self::netByUser((int) $sd->id), fn ($v) => abs($v) >= 0.005);
        ksort($want);
        ksort($net);
        $same = count($want) === count($net) && ! array_filter($want, fn ($v, $k) => abs($v - (float) ($net[$k] ?? -999)) >= 0.005, ARRAY_FILTER_USE_BOTH);
        if ($same) {
            if ($open) DB::table('hr_leave_rechecks')->where('id', $open->id)->update(['status' => 'cleared', 'resolved_at' => now(), 'updated_at' => now(), 'note' => 'The amount matches again']);

            return 0;
        }
        $vals = ['booked' => round(array_sum($net), 2), 'fresh' => round(array_sum($want), 2), 'cause' => mb_substr($cause . ($closure ? '' : ' (the closure was removed)'), 0, 255), 'updated_at' => now()];
        if ($open) {
            DB::table('hr_leave_rechecks')->where('id', $open->id)->update($vals);

            return 0;
        }
        DB::table('hr_leave_rechecks')->insert($vals + ['agency_id' => (int) $sd->agency_id, 'time_off_request_id' => null, 'shutdown_id' => (int) $sd->id, 'user_id' => null,
            'leave_type_id' => (int) $type->id, 'unit' => $type->unit, 'closure_id' => $sd->closure_id, 'status' => 'open', 'created_at' => now()]);

        return 1;
    }
}
