<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LUNCH AND BREAKS (2026-11-05).
 *
 * Anthony: "if educator wants to sign out for lunch or break - wire something up to allow
 * for that which doesnt completely sign the out for the day".
 *
 * A break CLOSES the shift row (time_punches.punched_out_at = start, out_reason = lunch|break)
 * and opens a time_punch_breaks row. Closing it is the point: every reader that asks "who is
 * on the floor" (ratios, team widgets, reminders, auto sign-off) already treats a closed row
 * as off the floor, so none of them can count somebody at lunch in a room.
 *
 * Coming back:
 *   unpaid  a NEW shift row starts at the return - the gap is not worked time, and every
 *           hours total already sums row by row;
 *   paid    the SAME row reopens (punched_out_at back to NULL) - the break stays inside the
 *           shift and is paid with it.
 * Which is which is the agency's rule (Payroll settings → Lunch and breaks), copied onto the
 * break row when it starts so changing the rule later never rewrites a break already taken.
 *
 * "On break" is an open break row less than OPEN_HOURS old. Ending the day from a break
 * closes the break and leaves the shift ending when the break began.
 */
final class StaffBreaks
{
    public const KEY = 'staff_breaks';
    public const KINDS = ['lunch' => 'Lunch', 'break' => 'Break'];
    /* Overrun (2026-11-06, Anthony: "when on lunch or break and the break or lunch exceeds a threshold - warn and
       remind director/admin and educator appropriately"). At the limit the educator is reminded; overrun_escalate_after
       minutes later the centre's directors and/or the agency's admins are alerted (BreakOverrunCommand). */
    public const DEFAULTS = ['lunch_paid' => false, 'break_paid' => true,
        'overrun_enabled' => true, 'lunch_limit' => 30, 'break_limit' => 15, 'overrun_escalate_after' => 10,
        'overrun_notify_directors' => true, 'overrun_notify_admins' => true];
    /** Allowed range for each minute setting. */
    public const MINUTES = ['lunch_limit' => [5, 240], 'break_limit' => [5, 120], 'overrun_escalate_after' => [0, 120]];
    /** An open break older than this is not "on break" any more - they never came back. */
    public const OPEN_HOURS = 6;

    public static function read(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $in = is_array($s[self::KEY] ?? null) ? $s[self::KEY] : [];
        return self::clean($in);
    }

    /** Defaults filled in, booleans as booleans, minutes clamped to their range. */
    public static function clean(array $in): array
    {
        $out = self::DEFAULTS;
        foreach (array_keys(self::DEFAULTS) as $k) {
            if (! array_key_exists($k, $in)) continue;
            if (isset(self::MINUTES[$k])) {
                [$lo, $hi] = self::MINUTES[$k];
                $out[$k] = max($lo, min($hi, (int) $in[$k]));
            } else {
                $out[$k] = (bool) $in[$k];
            }
        }

        return $out;
    }

    public static function write(int $agencyId, array $next): array
    {
        $clean = self::clean($next);
        DB::transaction(function () use ($agencyId, $clean) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $s[self::KEY] = $clean;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });

        return $clean;
    }

    /** The open break for this person, or null. */
    public static function current(int $userId): ?object
    {
        return DB::table('time_punch_breaks')->where('user_id', $userId)->whereNull('ended_at')
            ->where('started_at', '>=', Carbon::now()->subHours(self::OPEN_HOURS))
            ->orderByDesc('id')->first();
    }

    /** user_id => open break, for a list of people (team widgets). */
    public static function currentFor(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (! $userIds) return [];
        $out = [];
        foreach (DB::table('time_punch_breaks')->whereIn('user_id', $userIds)->whereNull('ended_at')
            ->where('started_at', '>=', Carbon::now()->subHours(self::OPEN_HOURS))->orderBy('id')->get() as $b) {
            $out[(int) $b->user_id] = $b;
        }

        return $out;
    }

    /** Open breaks at a centre right now (for "On break" lists). */
    public static function atCentre(int $centreId): array
    {
        return DB::table('time_punch_breaks as b')->join('users as u', 'u.id', '=', 'b.user_id')
            ->where('b.centre_id', $centreId)->whereNull('b.ended_at')
            ->where('b.started_at', '>=', Carbon::now()->subHours(self::OPEN_HOURS))
            ->orderBy('b.started_at')
            ->get(['b.user_id', 'b.kind', 'b.started_at', 'b.paid', 'u.first_name', 'u.last_name'])->all();
    }

    private static function agencyOfCentre(int $centreId): int
    {
        return (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
    }

    /**
     * Start a break: close the open shift row and open the break.
     * @return array{ok:bool,message?:string,break?:object}
     */
    public static function start(int $userId, string $kind): array
    {
        if (! isset(self::KINDS[$kind])) return ['ok' => false, 'message' => 'Pick lunch or break.'];

        return DB::transaction(function () use ($userId, $kind) {
            $open = DB::table('time_punches')->where('user_id', $userId)->whereNull('punched_out_at')
                ->orderByDesc('punched_in_at')->lockForUpdate()->first();
            if (! $open) return ['ok' => false, 'message' => "You're not clocked in, so there is no shift to take a break from."];
            $centreId = (int) $open->centre_id;
            $rule = self::read(self::agencyOfCentre($centreId));
            $now = now();
            DB::table('time_punches')->where('id', $open->id)->update(['punched_out_at' => $now, 'out_reason' => $kind]);
            $id = DB::table('time_punch_breaks')->insertGetId([
                'user_id' => $userId, 'centre_id' => $centreId ?: null, 'punch_id' => (int) $open->id,
                'kind' => $kind, 'paid' => (bool) ($rule[$kind . '_paid'] ?? false),
                'started_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);

            return ['ok' => true, 'break' => DB::table('time_punch_breaks')->where('id', $id)->first(), 'punch' => $open];
        });
    }

    /**
     * Back from the break: reopen the row (paid) or start a new one (unpaid).
     * @return array{ok:bool,message?:string,break?:object,punch_id?:int,minutes?:int}
     */
    public static function end(int $userId, string $source = 'web'): array
    {
        return DB::transaction(function () use ($userId, $source) {
            $b = DB::table('time_punch_breaks')->where('user_id', $userId)->whereNull('ended_at')
                ->where('started_at', '>=', Carbon::now()->subHours(self::OPEN_HOURS))
                ->orderByDesc('id')->lockForUpdate()->first();
            if (! $b) return ['ok' => false, 'message' => "You're not on a break."];
            if (DB::table('time_punches')->where('user_id', $userId)->whereNull('punched_out_at')->exists()) {
                // Already back on the clock some other way (an admin edit, another device): just close the break.
                DB::table('time_punch_breaks')->where('id', $b->id)->update(['ended_at' => now(), 'ended_how' => 'back', 'updated_at' => now()]);
                return ['ok' => true, 'break' => $b, 'punch_id' => null, 'minutes' => self::minutes($b)];
            }
            $now = now();
            $punch = DB::table('time_punches')->where('id', $b->punch_id)->first();
            if ($b->paid && $punch && $punch->out_reason === $b->kind) {
                DB::table('time_punches')->where('id', $punch->id)->update(['punched_out_at' => null, 'out_reason' => null]);
                $pid = (int) $punch->id;
            } else {
                $pid = (int) DB::table('time_punches')->insertGetId([
                    'user_id' => $userId, 'centre_id' => $b->centre_id ?: ($punch->centre_id ?? null),
                    'punched_in_at' => $now, 'notes' => 'Back from ' . strtolower(self::KINDS[$b->kind] ?? 'break'),
                    'source' => in_array($source, ['web', 'kiosk', 'mobile', 'auto'], true) ? $source : 'web',
                    'created_at' => $now,
                ]);
            }
            DB::table('time_punch_breaks')->where('id', $b->id)->update(['ended_at' => $now, 'ended_how' => 'back', 'return_punch_id' => $pid, 'updated_at' => $now]);

            return ['ok' => true, 'break' => $b, 'punch_id' => $pid, 'minutes' => self::minutes($b)];
        });
    }

    /** End the day from a break: the shift already ended when the break began. */
    public static function endDay(int $userId): ?object
    {
        $b = self::current($userId);
        if (! $b) return null;
        DB::table('time_punch_breaks')->where('id', $b->id)->update(['ended_at' => now(), 'ended_how' => 'day_end', 'updated_at' => now()]);
        DB::table('time_punches')->where('id', $b->punch_id)->where('out_reason', $b->kind)->update(['out_reason' => null]);

        return $b;
    }

    public static function minutes(object $b): int
    {
        return (int) abs(Carbon::parse($b->started_at)->diffInMinutes($b->ended_at ? Carbon::parse($b->ended_at) : now()));
    }

    /** The shape the clients read. */
    public static function present(?object $b): ?array
    {
        if (! $b) return null;

        return ['id' => (int) $b->id, 'kind' => $b->kind, 'label' => self::KINDS[$b->kind] ?? 'Break',
            'paid' => (bool) $b->paid, 'started_at' => (string) $b->started_at];
    }
}
