<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\FcmService;
use App\Support\Notify;
use App\Support\SleepChecks;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OVERDUE SLEEP CHECKS (2026-10-13; Ontario O. Reg. 137/15 s.33.1).
 *
 * Every minute: for each child under 24 months who is asleep (an open nap, see
 * App\Support\SleepChecks) at a centre where sleep checks are on, if the next direct
 * visual check is overdue, tell the room's educators (bell + push) once; if it is still
 * overdue after the director grace period, tell the centre's directors once.
 *
 * One query per run on the server, instead of every educator's phone polling: the
 * shared host caps concurrent PHP. Phones count down locally from the due time.
 * "Once" is enforced by a unique row in sleep_check_alerts (nap, due time, level),
 * written BEFORE anybody is told, so two overlapping runs cannot both send.
 *
 * --dry-run lists what it would send and sends nothing.
 */
final class SleepChecksAlertCommand extends Command
{
    protected $signature = 'sleep-checks:alert {--dry-run}';

    protected $description = 'Alert room educators (and then directors) when a sleeping child under 24 months is overdue for a sleep check';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $now = Carbon::now('UTC');
        $sent = 0;

        foreach (SleepChecks::openNaps(null, $now) as $n) {
            if (! $n['enabled'] || ! $n['overdue']) continue;
            $due = $n['due'];
            $late = (int) floor($due->diffInSeconds($now, true) / 60);
            $dueS = $due->format('Y-m-d H:i:s');
            $title = 'Sleep check overdue: ' . $n['first_name'];
            $body = $n['first_name'] . ' (' . $n['room_name'] . ') was due a sleep check at ' . $n['due_local']
                . ($n['last_check'] ? ', last checked ' . $n['last_check']['at_local'] : ', asleep since ' . $n['since_local']) . '.';

            $levels = [['room', $this->roomEducators($n)]];
            if ($n['cfg']['alert_director'] && $late >= (int) $n['cfg']['director_grace_minutes']) {
                $levels[] = ['director', $this->directors($n)];
            }

            foreach ($levels as [$level, $users]) {
                if (DB::table('sleep_check_alerts')->where('nap_event_id', $n['nap_event_id'])->where('due_at', $dueS)->where('level', $level)->exists()) continue;
                if ($dry) {
                    $this->line("[dry] {$level} nap {$n['nap_event_id']} {$n['name']} due {$dueS} -> users " . implode(',', $users));
                    continue;
                }
                $claimed = DB::table('sleep_check_alerts')->insertOrIgnore([
                    'nap_event_id' => $n['nap_event_id'], 'due_at' => $dueS, 'level' => $level, 'recipients' => count($users), 'created_at' => now(),
                ]);
                if (! $claimed) continue;   // another run got there first

                $msg = $level === 'director' ? $body . ' Still not checked ' . $late . ' min later.' : $body;
                foreach ($users as $uid) {
                    Notify::write(['user_id' => $uid, 'type' => 'sleep_check', 'title' => $title, 'body' => $msg,
                        'data' => json_encode(['link' => '#sleep-room?room=' . $n['room_id'], 'child_id' => $n['child_id'], 'nap_event_id' => $n['nap_event_id']]), 'created_at' => now()]);
                    try {
                        app(FcmService::class)->sendToUser($uid, $title, $msg, '#sleep-room?room=' . $n['room_id']);
                    } catch (\Throwable $e) {
                        Log::warning('Sleep check push failed', ['user' => $uid, 'error' => $e->getMessage()]);
                    }
                    $sent++;
                }
            }
        }

        if ($sent || $dry) $this->info("Sleep check alerts sent: {$sent}");

        return self::SUCCESS;
    }

    /**
     * Educators assigned to the room, preferring those clocked in at the centre right now.
     * Only the educator role: home visitors and admins are assigned to rooms for oversight,
     * not to watch a nap.
     */
    private function roomEducators(array $n): array
    {
        $ids = DB::table('educator_rooms as er')->join('role_assignments as ra', 'ra.user_id', '=', 'er.user_id')
            ->join('users as u', 'u.id', '=', 'er.user_id')
            ->where('er.room_id', $n['room_id'])->where('ra.active', true)->where('ra.role', 'educator')
            ->where(fn ($w) => $w->where('ra.agency_id', $n['agency_id'])->orWhere('ra.centre_id', $n['centre_id']))
            ->whereNull('u.deleted_at')
            ->distinct()->pluck('er.user_id')->map(fn ($v) => (int) $v)->all();
        if (! $ids) return [];
        $on = DB::table('time_punches')->whereIn('user_id', $ids)->where('centre_id', $n['centre_id'])->whereNull('punched_out_at')
            ->where('punched_in_at', '>=', Carbon::now('UTC')->subHours(14)->format('Y-m-d H:i:s'))
            ->distinct()->pluck('user_id')->map(fn ($v) => (int) $v)->all();

        return $on ?: $ids;
    }

    /** The centre's directors; with none, the agency's admins. */
    private function directors(array $n): array
    {
        $ids = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', true)->where('ra.role', 'centre_director')->where('ra.centre_id', $n['centre_id'])->whereNull('u.deleted_at')
            ->distinct()->pluck('ra.user_id')->map(fn ($v) => (int) $v)->all();
        if ($ids) return $ids;

        return DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', true)->where('ra.role', 'agency_admin')->where('ra.agency_id', $n['agency_id'])->whereNull('u.deleted_at')
            ->distinct()->pluck('ra.user_id')->map(fn ($v) => (int) $v)->all();
    }
}
