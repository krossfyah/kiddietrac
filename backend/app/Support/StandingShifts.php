<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * STANDING HOURS — the shifts a person works at a home every week (2026-10-21).
 *
 * Anthony: Rachel Blane, Bruni Meeser's second educator, saw "No shifts scheduled"
 * because the nightly fill (schedule:autofill) rosters only a home's designated provider.
 * He wanted her on Bruni's hours, and "allow us to define her shift as well when
 * required". So each person on a home's team (App\Support\CentreTeam) may carry a
 * pattern, kept in centres.settings.standing_shifts:
 *
 *   { "<user id>": { "start": "07:00", "end": "17:00", "days": [1,2,3,4,5], "role": "support" } }
 *
 * The nightly job keeps everyone with a pattern on the schedule, at their own hours and
 * days. The provider without one keeps the centre's hours, as before. Nobody else is
 * rostered by a machine unless a person wrote this pattern for them: the rule that came
 * out of Amna Ahsan's rota still holds.
 *
 * A single day is still changed on the calendar itself (edit the shift); this is only
 * the standing week.
 */
final class StandingShifts
{
    public const KEY = 'standing_shifts';
    public const ROLES = ['lead', 'support', 'floater', 'volunteer'];

    /** @return array<int, array{start:string,end:string,days:int[],role:string}> */
    public static function patterns($centre): array
    {
        $s = json_decode((string) ($centre->settings ?? ''), true);
        $raw = is_array($s) ? ($s[self::KEY] ?? []) : [];
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $uid => $p) {
            if (is_array($p) && ($n = self::normalise($p))) {
                $out[(int) $uid] = $n;
            }
        }

        return $out;
    }

    /** A clean pattern, or null when the input does not make one. */
    public static function normalise(array $p): ?array
    {
        $start = self::hm($p['start'] ?? null);
        $end = self::hm($p['end'] ?? null);
        if (! $start || ! $end || $end <= $start) {
            return null;
        }
        $days = array_values(array_unique(array_filter(
            array_map('intval', (array) ($p['days'] ?? [])), fn ($d) => $d >= 1 && $d <= 7)));
        sort($days);
        if (! $days) {
            return null;
        }
        $role = in_array($p['role'] ?? null, self::ROLES, true) ? $p['role'] : 'support';

        return ['start' => $start, 'end' => $end, 'days' => $days, 'role' => $role];
    }

    /** Write (or with null, remove) one person's pattern on a centre. */
    public static function put(int $centreId, int $userId, ?array $pattern): void
    {
        $s = json_decode((string) DB::table('centres')->where('id', $centreId)->value('settings'), true);
        $s = is_array($s) ? $s : [];
        $all = is_array($s[self::KEY] ?? null) ? $s[self::KEY] : [];
        if ($pattern) {
            $all[(string) $userId] = $pattern;
        } else {
            unset($all[(string) $userId]);
        }
        if ($all) {
            $s[self::KEY] = $all;
        } else {
            unset($s[self::KEY]);
        }
        DB::table('centres')->where('id', $centreId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
    }

    /**
     * Write one person's shifts at a centre from $from to $to (local dates) by a pattern:
     * only the pattern's weekdays, never a closure day, never a day that person already
     * has a shift here. With $replace, their still-"scheduled" shifts in the window are
     * removed first, so a change of hours takes effect from $from on (worked, active and
     * cancelled shifts are never touched).
     *
     * @return array{created:int, removed:int}
     */
    public static function fill($centre, int $userId, ?array $pattern, Carbon $from, Carbon $to, bool $replace): array
    {
        $rooms = DB::table('rooms')->where('centre_id', $centre->id)->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
        if (! $rooms || $to->lt($from)) {
            return ['created' => 0, 'removed' => 0];
        }
        $fromS = $from->toDateString() . ' 00:00:00';
        $toS = $to->toDateString() . ' 23:59:59';

        $removed = 0;
        if ($replace) {
            $removed = DB::table('shifts')->where('user_id', $userId)->whereIn('room_id', $rooms)
                ->where('status', 'scheduled')->whereBetween('starts_at', [$fromS, $toS])->delete();
        }
        if (! $pattern) {
            return ['created' => 0, 'removed' => $removed];
        }

        $room = (int) (DB::table('educator_rooms')->where('user_id', $userId)->whereIn('room_id', $rooms)->value('room_id') ?: $rooms[0]);
        $closed = self::closureDates((int) $centre->id, $from, $to);
        $have = [];
        foreach (DB::table('shifts')->where('user_id', $userId)->whereIn('room_id', $rooms)
            ->whereBetween('starts_at', [$fromS, $toS])->pluck('starts_at') as $st) {
            $have[substr((string) $st, 0, 10)] = true;
        }

        $rows = [];
        $now = now();
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            $date = $d->toDateString();
            if (! in_array((int) $d->isoWeekday(), $pattern['days'], true) || isset($closed[$date]) || isset($have[$date])) {
                continue;
            }
            $rows[] = [
                'user_id' => $userId, 'room_id' => $room,
                'starts_at' => $date . ' ' . $pattern['start'] . ':00',
                'ends_at' => $date . ' ' . $pattern['end'] . ':00',
                'role' => $pattern['role'], 'status' => 'scheduled', 'created_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('shifts')->insert($chunk);
        }

        return ['created' => count($rows), 'removed' => $removed];
    }

    /** Every closed date in the window (a closure may span several days). */
    public static function closureDates(int $centreId, Carbon $start, Carbon $end): array
    {
        $map = [];
        foreach (DB::table('centre_closures')->where('centre_id', $centreId)
            ->where('closure_date', '<=', $end->toDateString())
            ->where(function ($q) use ($start) {
                $q->where('end_date', '>=', $start->toDateString())->orWhereNull('end_date');
            })->get() as $r) {
            $f = Carbon::parse($r->closure_date)->startOfDay();
            $t = Carbon::parse($r->end_date ?: $r->closure_date)->startOfDay();
            if ($t->lt($f)) { $t = $f->copy(); }
            for ($d = $f->copy(); $d->lte($t); $d->addDay()) {
                $map[$d->toDateString()] = true;
            }
        }

        return $map;
    }

    private static function hm($v): ?string
    {
        return is_string($v) && preg_match('/^([01]\d|2[0-3]):([0-5]\d)/', $v, $m) ? $m[1] . ':' . $m[2] : null;
    }
}
