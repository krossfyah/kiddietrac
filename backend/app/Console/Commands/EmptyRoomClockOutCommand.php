<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\Api\ClockRemindersController;
use App\Support\AgencyTime;
use App\Support\Notify;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * STILL CLOCKED IN WITH NO CHILDREN (2026-11-07).
 *
 * Anthony: "with educators who have no kids checked in and are still clocked in - send app reminders that they are
 * still clocked in and to clock out - wire this up as a setting as well". An app reminder (bell + push, Notify) to
 * an educator on the clock once every child has left their room(s) - not an email.
 *
 * When it fires: the educator has an open punch, at least one child was in their room(s) today, none is in now, and
 * the last one left empty_after minutes ago (Clock settings → Reminders). Never before the first child arrives, so an
 * educator opening the room at 7:00 is not told to go home. Repeats every empty_repeat minutes (0 = once), at most
 * MAX times; children coming back resets it. Their rooms are educator_rooms; with none, the centre they punched in at.
 * Educators only - a director or admin on the clock runs the place, not a room.
 *
 * Runs every five minutes. --dry-run says who would be told and changes nothing.
 */
class EmptyRoomClockOutCommand extends Command
{
    public const MAX = 3;

    protected $signature = 'staff:empty-room-clock-out {--dry-run : say who would be told; send and change nothing} {--agency= : one agency only}';

    protected $description = 'Remind educators still clocked in after every child has left their room to clock out';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $only = $this->option('agency') ? (int) $this->option('agency') : null;
        $open = DB::table('time_punches as p')
            ->join('centres as ce', 'ce.id', '=', 'p.centre_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->whereNull('p.punched_out_at')
            ->where('p.punched_in_at', '>=', Carbon::now()->subHours(16))
            ->where('p.empty_reminders', '<', self::MAX)
            ->whereNull('u.deleted_at')
            ->when($only, fn ($q) => $q->where('ce.agency_id', $only))
            ->get(['p.id', 'p.user_id', 'p.centre_id', 'p.punched_in_at', 'p.empty_reminded_at', 'p.empty_reminders', 'ce.agency_id', 'u.first_name']);

        $cfg = [];
        $sent = 0;
        foreach ($open as $p) {
            $agencyId = (int) $p->agency_id;
            $s = $cfg[$agencyId] ??= ClockRemindersController::read($agencyId);
            if (empty($s['empty_enabled'])) {
                continue;
            }
            if (! $this->isFloorEducator((int) $p->user_id, $agencyId)) {
                continue;
            }

            [$from, $to] = AgencyTime::dayRange($agencyId);
            $since = Carbon::parse($p->punched_in_at);
            if ($since->lt(Carbon::parse($from))) $since = Carbon::parse($from);
            $events = DB::table('check_events')
                ->whereIn('room_id', $this->roomsOf((int) $p->user_id, (int) $p->centre_id) ?: [0])
                ->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)
                ->whereIn('event_type', ['check_in', 'check_out'])
                ->orderBy('occurred_at')->orderBy('id')
                ->get(['child_id', 'event_type', 'occurred_at']);
            if ($events->isEmpty()) {
                continue;   // no child has come today: not "everyone has gone"
            }
            $last = [];
            foreach ($events as $e) {
                $last[(int) $e->child_id] = $e;
            }
            $here = collect($last)->filter(fn ($e) => $e->event_type === 'check_in')->count();
            if ($here > 0) {
                // Children are back: the next empty spell starts its own reminders.
                if ($p->empty_reminders > 0 && ! $dry) {
                    DB::table('time_punches')->where('id', $p->id)->update(['empty_reminders' => 0, 'empty_reminded_at' => null]);
                }
                continue;
            }
            $emptySince = Carbon::parse(collect($last)->max('occurred_at'));
            if ($emptySince->lt($since)) $emptySince = $since->copy();
            $after = max(5, (int) $s['empty_after']);
            $repeat = max(0, (int) $s['empty_repeat']);
            if ($emptySince->diffInMinutes(now()) < $after) {
                continue;
            }
            if ($p->empty_reminders > 0 && ($repeat === 0 || Carbon::parse($p->empty_reminded_at)->diffInMinutes(now()) < $repeat)) {
                continue;
            }
            if (! $this->claim($p, $dry)) {
                continue;
            }

            $tz = AgencyTime::tz($agencyId);
            $left = $emptySince->copy()->timezone($tz)->format('g:i A');
            $in = Carbon::parse($p->punched_in_at)->timezone($tz)->format('g:i A');
            $title = '⏱️ You are still clocked in';
            $body = 'Every child has gone from your room (since ' . $left . '), and you have been on the clock since ' . $in
                . '. If your day is done, clock out now so your hours are right.';
            $this->line(($dry ? '[dry] ' : '') . "remind user {$p->user_id} ({$p->first_name}), punch {$p->id}: room empty since {$left}");
            if (! $dry) {
                Notify::write(['agency_id' => $agencyId, 'user_id' => (int) $p->user_id, 'type' => 'clock_reminder', 'title' => $title, 'body' => $body,
                    'data' => json_encode(['link' => '#time-clock']), 'created_at' => now()]);
            }
            $sent++;
        }
        if ($sent || $dry) {
            $this->info(($dry ? '[dry] ' : '') . "{$sent} empty-room clock-out reminder(s)");
        }

        return self::SUCCESS;
    }

    /** An educator in this agency who is not also its director or admin. */
    private function isFloorEducator(int $userId, int $agencyId): bool
    {
        $roles = DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)
            ->where(fn ($q) => $q->where('agency_id', $agencyId)->orWhereNull('agency_id'))->pluck('role')->all();

        return in_array('educator', $roles, true)
            && ! array_intersect(['centre_director', 'agency_admin', 'platform_admin'], $roles);
    }

    /** Their rooms; with none assigned, every room of the centre they punched in at. */
    private function roomsOf(int $userId, int $centreId): array
    {
        $rooms = DB::table('educator_rooms')->where('user_id', $userId)->pluck('room_id')->filter()->map(fn ($v) => (int) $v)->all();

        return $rooms ?: DB::table('rooms')->where('centre_id', $centreId)->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** Take this reminder; false if another run already did. A dry run claims nothing. */
    private function claim(object $p, bool $dry): bool
    {
        if ($dry) {
            return true;
        }

        return DB::table('time_punches')->where('id', $p->id)->where('empty_reminders', (int) $p->empty_reminders)
            ->update(['empty_reminders' => (int) $p->empty_reminders + 1, 'empty_reminded_at' => now()]) === 1;
    }
}
