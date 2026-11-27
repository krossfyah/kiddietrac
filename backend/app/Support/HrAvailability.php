<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KIDDIETRAC HR PHASE 3, ITEM 10: SCHEDULING UPGRADES (2026-11-26), built on the EXISTING shifts / standing rota / autofill.
 *
 * ONE RULE for "can this person work this shift?" (blocks()), used by both autofill paths (SchedulingController::autofill,
 * StandingShifts::fill / schedule:autofill) and the planning view, so the rota and the warnings never disagree:
 *   1. approved leave (time_off_requests status approved, the shift's date inside start_at..end_at; a part-day request
 *      only when its hours overlap the shift);
 *   2. one-off unavailability (hr_unavailability, all day or a time window);
 *   3. weekly availability (hr_availability): when a person has set ANY, a shift must sit inside one of that weekday's
 *      windows. Nobody set = available any time (never assumed unavailable).
 * Times are agency wall-clock times, as shifts store them.
 *
 * COST (planning view): shift hours x users.pay_rate for pay_type hourly; salaried / per-visit staff have no per-shift
 * cost. Shown ONLY to someone who can see pay today: a director / admin AND the payroll feature on (HrController::canSeePay).
 *
 * RATIO WARNINGS (planning view): expected children per room per day (enrolled, primary room, their attendance pattern
 * for that weekday; no pattern = Monday to Friday) through RatioRules::evaluate with the PLANNED educators, hour by
 * hour over the centre's open hours.
 */
final class HrAvailability
{
    public const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    private const PATTERN_COL = [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday'];

    /** @var array<string,mixed> per-request memo */
    private static array $memo = [];

    public static function on(): bool
    {
        return Schema::hasTable('hr_availability');
    }

    private static function hm(?string $t): string
    {
        return substr((string) $t, 0, 5);
    }

    private static function overlaps(string $aStart, string $aEnd, string $bStart, string $bEnd): bool
    {
        return self::hm($aStart) < self::hm($bEnd) && self::hm($bStart) < self::hm($aEnd);
    }

    /** Weekly windows: [dow => [[start, end], ...]]; [] = not set (available any time). */
    public static function weekly(int $agencyId, int $userId): array
    {
        $k = "w:$agencyId:$userId";
        if (isset(self::$memo[$k])) return self::$memo[$k];
        $out = [];
        if (self::on()) {
            foreach (DB::table('hr_availability')->where('agency_id', $agencyId)->where('user_id', $userId)->orderBy('dow')->orderBy('start_time')
                ->get(['dow', 'start_time', 'end_time']) as $r) {
                $out[(int) $r->dow][] = [self::hm($r->start_time), self::hm($r->end_time)];
            }
        }

        return self::$memo[$k] = $out;
    }

    /**
     * Why this person cannot work this shift, or null when they can. $date = Y-m-d, times H:i or H:i:s (agency wall clock).
     */
    public static function blocks(int $agencyId, int $userId, string $date, string $start, string $end): ?string
    {
        // 1. approved leave
        foreach (DB::table('time_off_requests')->where('user_id', $userId)->where('status', 'approved')
            ->whereDate('start_at', '<=', $date)->whereDate('end_at', '>=', $date)
            ->get(['request_type', 'all_day', 'start_time', 'end_time']) as $t) {
            $part = isset($t->all_day) && ! $t->all_day && $t->start_time && $t->end_time;
            if (! $part || self::overlaps($start, $end, (string) $t->start_time, (string) $t->end_time)) {
                return 'On approved leave';
            }
        }
        if (! self::on()) return null;
        // 2. one-off unavailability
        foreach (DB::table('hr_unavailability')->where('agency_id', $agencyId)->where('user_id', $userId)
            ->where('date_from', '<=', $date)->where('date_to', '>=', $date)->get(['start_time', 'end_time', 'reason']) as $u) {
            if (! $u->start_time || ! $u->end_time || self::overlaps($start, $end, (string) $u->start_time, (string) $u->end_time)) {
                return 'Marked unavailable' . ($u->reason ? ' (' . $u->reason . ')' : '');
            }
        }
        // 3. weekly availability (only when the person has set some)
        $w = self::weekly($agencyId, $userId);
        if ($w) {
            $dow = (int) Carbon::parse($date)->isoWeekday();
            foreach ($w[$dow] ?? [] as [$a, $b]) {
                if (self::hm($start) >= $a && self::hm($end) <= $b) return null;
            }

            return ($w[$dow] ?? null) ? 'Outside their availability on ' . self::DAYS[$dow] . 's' : 'Not available on ' . self::DAYS[$dow] . 's';
        }

        return null;
    }

    /**
     * For the autofill paths: blocks() for a centre's shift, only when the centre's agency has the HR add-on (agencies
     * without HR roster exactly as before). Fails open (null) only when the agency cannot be found.
     */
    public static function blocksFor(object $centre, int $userId, string $date, string $start, string $end): ?string
    {
        $cid = (int) ($centre->id ?? 0);
        $k = "c:$cid";
        if (! array_key_exists($k, self::$memo)) {
            $aid = (int) ($centre->agency_id ?? DB::table('centres')->where('id', $cid)->value('agency_id'));
            self::$memo[$k] = $aid && Features::on($aid, 'hr') ? $aid : 0;
        }
        $aid = (int) self::$memo[$k];

        return $aid ? self::blocks($aid, $userId, $date, $start, $end) : null;
    }

    /** Shift hours (decimal). */
    public static function hours(string $startsAt, string $endsAt): float
    {
        $m = max(0, (strtotime($endsAt) - strtotime($startsAt)) / 60);

        return round($m / 60, 2);
    }

    /** [user_id => [rate, type]] for the cost of a shift (hourly only). */
    public static function rates(array $userIds): array
    {
        return DB::table('users')->whereIn('id', $userIds ?: [0])->get(['id', 'pay_rate', 'pay_type'])
            ->mapWithKeys(fn ($u) => [(int) $u->id => [$u->pay_rate !== null ? (float) $u->pay_rate : null, $u->pay_type ?: 'hourly']])->all();
    }

    /**
     * Ratio shortfalls for a centre's planned week: [{date, room, from, to, planned, required}], merged into hour runs.
     * @param array<int, array<int, object>> $shiftsByRoom room id => shift rows (starts_at, ends_at, status)
     */
    public static function ratioGaps(object $centre, array $rooms, array $shiftsByRoom, Carbon $weekStart): array
    {
        $open = (int) substr((string) ($centre->open_time ?: '07:00'), 0, 2);
        $close = (int) ceil(((int) substr((string) ($centre->close_time ?: '18:00'), 0, 2)) + ((int) substr((string) ($centre->close_time ?: '18:00'), 3, 2)) / 60);
        if ($close <= $open) [$open, $close] = [7, 18];
        $out = [];
        $kids = DB::table('children')->whereIn('primary_room_id', array_map(fn ($r) => (int) $r->id, $rooms) ?: [0])
            ->where('enrollment_status', 'enrolled')->whereNull('deleted_at')->get(['id', 'primary_room_id'])->groupBy('primary_room_id');
        $patterns = DB::table('attendance_patterns')->whereIn('child_id', $kids->flatten()->pluck('id')->all() ?: [0])
            ->orderByDesc('effective_from')->get()->groupBy('child_id');
        for ($i = 0; $i < 7; $i++) {
            $day = $weekStart->copy()->addDays($i);
            $date = $day->toDateString();
            $dow = (int) $day->isoWeekday();
            foreach ($rooms as $room) {
                $expected = [];
                foreach ($kids[$room->id] ?? [] as $c) {
                    $pat = collect($patterns[$c->id] ?? [])->first(fn ($p) => (string) $p->effective_from <= $date && (! $p->effective_until || (string) $p->effective_until >= $date));
                    $comes = $pat ? (bool) $pat->{self::PATTERN_COL[$dow]} : $dow <= 5;
                    if ($comes) $expected[] = (int) $c->id;
                }
                if (! $expected) continue;
                $run = null;
                for ($h = $open; $h < $close; $h++) {
                    $at = sprintf('%s %02d:30:00', $date, $h);
                    $planned = 0;
                    foreach ($shiftsByRoom[$room->id] ?? [] as $s) {
                        if ($s->status !== 'cancelled' && (string) $s->starts_at <= $at && (string) $s->ends_at > $at) $planned++;
                    }
                    try {
                        $v = RatioRules::evaluate($room, Carbon::parse($at), $expected, [$planned, 'planned']);
                        $need = (int) ($v['required_educators'] ?? 0);
                    } catch (\Throwable $e) {
                        $need = (int) ceil(count($expected) / max(1, (int) $room->ratio_children));
                    }
                    if ($planned < $need) {
                        if ($run && $run['planned'] === $planned && $run['required'] === $need && $run['to'] === sprintf('%02d:00', $h)) {
                            $run['to'] = sprintf('%02d:00', $h + 1);
                        } else {
                            if ($run) $out[] = $run;
                            $run = ['date' => $date, 'room_id' => (int) $room->id, 'room' => $room->name, 'from' => sprintf('%02d:00', $h), 'to' => sprintf('%02d:00', $h + 1),
                                'planned' => $planned, 'required' => $need, 'children' => count($expected)];
                        }
                    } elseif ($run) {
                        $out[] = $run; $run = null;
                    }
                }
                if ($run) $out[] = $run;
            }
        }

        return $out;
    }
}
