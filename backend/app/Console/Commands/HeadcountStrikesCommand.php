<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AgencyTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * headcounts:strikes — MISSED HEADCOUNTS ARE STRIKES (2026-11-03, Anthony: "if there are missed head counts add this to
 * the educator daily summary and also in the daily overview section where headcount lives, mark this as a strike").
 *
 * Every "Headcount due" reminder headcounts:remind sends is recorded in headcount_strikes as pending. Once it is
 * GRACE_MINUTES old it is decided:
 *   the room was counted (by anyone) within the grace   -> cleared (counted)
 *   the educator clocked out within the grace           -> cleared (clocked_out)
 *   otherwise                                           -> strike, and the centre's directors get a bell + push
 * Anthony's choices: 15 minutes; a strike for each educator reminded; a count with someone unaccounted for is not a
 * strike (it is already flagged red on the count); directors are told.
 * Strikes show in Daily Overview (DayBriefController "headcount_strikes") and the educator's daily summary email.
 */
final class HeadcountStrikesCommand extends Command
{
    public const GRACE_MINUTES = 15;

    protected $signature = 'headcounts:strikes {--dry}';
    protected $description = 'Decide pending headcount reminders: counted in time, or a strike (directors told)';

    public function handle(): int
    {
        if (! Schema::hasTable('headcount_strikes')) return self::SUCCESS;
        $cut = now()->subMinutes(self::GRACE_MINUTES);
        $pending = DB::table('headcount_strikes')->where('status', 'pending')->where('due_at', '<=', $cut)
            ->orderBy('due_at')->limit(500)->get();
        $struck = [];   // "centre|room|due" => [row, [user ids]]

        foreach ($pending as $p) {
            $until = \Illuminate\Support\Carbon::parse($p->due_at, 'UTC')->addMinutes(self::GRACE_MINUTES);
            $counted = DB::table('headcounts')->where('room_id', $p->room_id)
                ->whereBetween('taken_at', [$p->due_at, $until])->exists();
            $left = ! $counted && DB::table('time_punches')->where('user_id', $p->user_id)->where('centre_id', $p->centre_id)
                ->whereBetween('punched_out_at', [$p->due_at, $until])->exists();
            $status = ($counted || $left) ? 'cleared' : 'strike';
            if ($this->option('dry')) {
                $this->line("#{$p->id} room {$p->room_id} user {$p->user_id} due {$p->due_at}: {$status}");
                continue;
            }
            DB::table('headcount_strikes')->where('id', $p->id)->where('status', 'pending')->update([
                'status' => $status, 'cleared_by' => $counted ? 'counted' : ($left ? 'clocked_out' : null), 'decided_at' => now(),
            ]);
            if ($status === 'strike') {
                $k = $p->centre_id . '|' . $p->room_id . '|' . $p->due_at;
                $struck[$k][0] = $p;
                $struck[$k][1][] = (int) $p->user_id;
            }
        }

        // One notice per missed room count to the centre's directors, naming the educators (first names).
        foreach ($struck as [$p, $users]) {
            try {
                $room = (string) (DB::table('rooms')->where('id', $p->room_id)->value('name') ?: 'a room');
                $names = DB::table('users')->whereIn('id', $users)->pluck('first_name')->filter()->values()->all();
                $tz = AgencyTime::tz((int) $p->agency_id) ?: 'America/Toronto';
                $at = AgencyTime::fmt($p->due_at, $tz);
                $who = $names ? implode(count($names) === 2 ? ' and ' : ', ', $names) : 'The educator';
                $directors = DB::table('role_assignments')->where('centre_id', $p->centre_id)->where('role', 'centre_director')
                    ->where('active', true)->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
                $rows = [];
                foreach ($directors as $d) {
                    $rows[] = ['user_id' => $d, 'type' => 'headcount_strike',
                        'title' => "\u{26A0}\u{FE0F} Missed headcount — " . $room,
                        'body' => $who . ' did not count ' . $room . ' within ' . self::GRACE_MINUTES . ' minutes of the ' . $at . ' reminder. Recorded as a strike.',
                        'data' => json_encode(['link' => '#provider-day', 'room_id' => (int) $p->room_id, 'kt_category' => 'headcount']),
                        'created_at' => now()];
                }
                if ($rows) \App\Support\Notify::write($rows);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return self::SUCCESS;
    }

    /** Strikes for a centre (or one educator) in a UTC range: [{user_id, due_at, room}]. */
    public static function between(?int $centreId, ?int $userId, string $from, string $to): array
    {
        if (! Schema::hasTable('headcount_strikes')) return [];
        $q = DB::table('headcount_strikes as s')->leftJoin('rooms as r', 'r.id', '=', 's.room_id')
            ->where('s.status', 'strike')->whereBetween('s.due_at', [$from, $to]);
        if ($centreId) $q->where('s.centre_id', $centreId);
        if ($userId) $q->where('s.user_id', $userId);
        return $q->orderBy('s.due_at')->get(['s.user_id', 's.due_at', 's.centre_id', 'r.name as room'])
            ->map(fn ($r) => ['user_id' => (int) $r->user_id, 'due_at' => (string) $r->due_at, 'room' => (string) ($r->room ?? ''), 'centre_id' => (int) $r->centre_id])->all();
    }
}
