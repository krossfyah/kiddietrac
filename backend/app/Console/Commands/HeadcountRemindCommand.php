<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AgencyTime;
use App\Support\Live;
use App\Support\RatioRules;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * headcounts:remind — OFF unless an agency switches it on (Headcounts screen, admins). For
 * every room with children signed in whose last headcount (or, if none today, whose first
 * sign-in) is older than the agency's interval, the educators clocked in and assigned to that
 * room get a bell + push: "Headcount due". At most once per interval per room. Never SMS,
 * never families. Ontario O. Reg. 137/15 s.11 (as amended by O. Reg. 197/26): the supervision
 * policy says how often children are counted; this keeps that rhythm.
 */
final class HeadcountRemindCommand extends Command
{
    protected $signature = 'headcounts:remind {--agency=} {--dry}';
    protected $description = 'Remind clocked-in educators when a room is due a routine headcount (agencies that switched it on)';

    public function handle(): int
    {
        if (! Schema::hasTable('headcounts')) return self::SUCCESS;
        $q = DB::table('agencies')->whereNull('deleted_at');
        if ($this->option('agency')) $q->where('id', (int) $this->option('agency'));
        foreach ($q->get(['id', 'settings']) as $a) {
            $s = $a->settings ? (json_decode((string) $a->settings, true) ?: []) : [];
            if (empty($s['headcount_reminders']['enabled'])) continue;
            $every = max(10, min(240, (int) ($s['headcount_reminders']['minutes'] ?? 30)));
            [$dayFrom, $dayTo] = AgencyTime::dayRange((int) $a->id);

            foreach (Live::centreIds((int) $a->id) as $cid) {
                $punched = DB::table('time_punches')->where('centre_id', $cid)->where('punched_in_at', '>=', $dayFrom)
                    ->where('punched_in_at', '<', $dayTo)->whereNull('punched_out_at')->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->all();
                if (! $punched) continue;

                foreach (DB::table('rooms')->where('centre_id', $cid)->get() as $room) {
                    $present = RatioRules::childIdsPresent($room, Carbon::now());
                    if (! $present) continue;
                    $last = DB::table('headcounts')->where('room_id', $room->id)->where('taken_at', '>=', $dayFrom)->max('taken_at')
                        ?: DB::table('check_events')->where('room_id', $room->id)->where('event_type', 'check_in')
                            ->where('occurred_at', '>=', $dayFrom)->where('occurred_at', '<=', now())->min('occurred_at');
                    if (! $last) continue;
                    $mins = (int) Carbon::parse($last, 'UTC')->diffInMinutes(now());
                    if ($mins < $every) continue;

                    $assigned = DB::table('educator_rooms')->where('room_id', $room->id)->whereIn('user_id', $punched)->pluck('user_id')->map(fn ($v) => (int) $v)->all();
                    if (! $assigned) {   // clocked-in educators at this centre with no room assignments cover every room
                        $withRooms = DB::table('educator_rooms')->whereIn('user_id', $punched)->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->all();
                        $assigned = DB::table('role_assignments')->where('centre_id', $cid)->where('role', 'educator')->where('active', true)
                            ->whereIn('user_id', array_values(array_diff($punched, $withRooms)) ?: [0])->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
                    }
                    if (! $assigned) continue;
                    if ($this->option('dry')) { $this->line("agency {$a->id} room {$room->id} {$room->name}: " . count($present) . " here, {$mins} min → " . count($assigned) . ' educator(s)'); continue; }
                    if (! Cache::add('kt-hc-remind:' . $room->id, 1, now()->addMinutes($every - 1))) continue;

                    $title = "\u{1F440} Headcount due — " . $room->name;
                    $body = count($present) . ' ' . (count($present) === 1 ? 'child' : 'children') . ' signed in. Last count ' . $mins . ' min ago. Tap to count.';
                    $link = '#headcounts?take=' . $room->id;
                    \App\Support\Notify::write(array_map(fn ($u) => ['user_id' => $u, 'type' => 'headcount_due', 'title' => $title, 'body' => $body,
                        'data' => json_encode(['link' => $link, 'room_id' => (int) $room->id]), 'created_at' => now()], $assigned));
                    foreach ($assigned as $u) {
                        try { app(\App\Services\FcmService::class)->sendToUser($u, $title, $body, $link); } catch (\Throwable $e) {}
                    }
                }
            }
        }

        return self::SUCCESS;
    }
}
