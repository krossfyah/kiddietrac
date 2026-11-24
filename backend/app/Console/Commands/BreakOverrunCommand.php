<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AgencyTime;
use App\Support\Notify;
use App\Support\StaffBreaks;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LUNCH / BREAK OVERRUN (2026-11-06).
 *
 * Anthony: "when on lunch or break and the break or lunch exceeds a threshold - warn and remind director/admin and
 * educator appropriately". Decisions the same day: lunch 30 min, break 15 by default (Payroll settings → Lunch and
 * breaks, per agency); the educator is reminded at the limit, and if they are still out overrun_escalate_after (10)
 * minutes later the centre's directors and/or the agency's admins are alerted.
 *
 * Each step goes out once per break (time_punch_breaks.reminded_at / escalated_at, claimed before sending, so two
 * runs cannot both send). An open break is "on break" for StaffBreaks::OPEN_HOURS; after that nobody is chased.
 * Runs every minute. --dry-run says who would be told and changes nothing.
 */
class BreakOverrunCommand extends Command
{
    protected $signature = 'staff:break-overrun {--dry-run : say who would be told; send and change nothing} {--agency= : one agency only}';

    protected $description = 'Remind staff whose lunch or break has run over its limit, then alert directors/admins';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $only = $this->option('agency') ? (int) $this->option('agency') : null;
        $open = DB::table('time_punch_breaks as b')
            ->join('centres as ce', 'ce.id', '=', 'b.centre_id')
            ->join('users as u', 'u.id', '=', 'b.user_id')
            ->whereNull('b.ended_at')
            ->where('b.started_at', '>=', Carbon::now()->subHours(StaffBreaks::OPEN_HOURS))
            ->where(fn ($q) => $q->whereNull('b.reminded_at')->orWhereNull('b.escalated_at'))
            ->whereNull('u.deleted_at')
            ->when($only, fn ($q) => $q->where('ce.agency_id', $only))
            ->get(['b.*', 'ce.agency_id', 'u.first_name', 'u.last_name']);

        $cfg = [];
        $sent = 0;
        foreach ($open as $b) {
            $agencyId = (int) $b->agency_id;
            $s = $cfg[$agencyId] ??= StaffBreaks::read($agencyId);
            if (! $s['overrun_enabled']) {
                continue;
            }
            // Back on the clock some other way (an admin edit, another device): not out any more.
            if (DB::table('time_punches')->where('user_id', $b->user_id)->whereNull('punched_out_at')->exists()) {
                continue;
            }
            $limit = (int) ($b->kind === 'lunch' ? $s['lunch_limit'] : $s['break_limit']);
            $mins = StaffBreaks::minutes($b);
            if ($mins < $limit) {
                continue;
            }
            $tz = AgencyTime::tz($agencyId);
            $what = strtolower(StaffBreaks::KINDS[$b->kind] ?? 'Break');
            $began = Carbon::parse($b->started_at)->timezone($tz)->format('g:i A');
            $first = $b->first_name ?: 'Someone';
            $name = trim($b->first_name . ' ' . $b->last_name) ?: 'A team member';

            // 1. The educator, at the limit.
            if (! $b->reminded_at && $this->claim($b->id, 'reminded_at', $dry)) {
                $title = '🍽️ Your ' . $what . ' is over ' . $limit . ' min';
                $body = "You started {$what} at {$began}. When you're back on the floor, tap I'm back on the clock.";
                $this->line(($dry ? '[dry] ' : '') . "remind {$name} (user {$b->user_id}): {$what} {$mins} min, limit {$limit}");
                if (! $dry) {
                    Notify::write(['agency_id' => $agencyId, 'user_id' => (int) $b->user_id, 'type' => 'break_overrun', 'title' => $title, 'body' => $body,
                        'data' => json_encode(['link' => '#time-clock']), 'created_at' => now()]);
                }
                $sent++;
            }

            // 2. Directors / admins, overrun_escalate_after minutes later.
            if (! $b->escalated_at && $mins >= $limit + (int) $s['overrun_escalate_after']) {
                $ids = $this->managers($agencyId, (int) $b->centre_id, (int) $b->user_id, $s);
                if (! $ids) {
                    continue;
                }
                if (! $this->claim($b->id, 'escalated_at', $dry)) {
                    continue;
                }
                $title = "⏰ {$first} is {$mins} min into {$what}";
                $body = "{$name} started {$what} at {$began} (the limit is {$limit} min) and hasn't come back on the clock. "
                    . "{$first} is off the floor and out of your ratios until then.";
                $this->line(($dry ? '[dry] ' : '') . "alert " . count($ids) . " manager(s) about {$name}: {$what} {$mins} min");
                if (! $dry) {
                    $rows = [];
                    foreach ($ids as $uid) {
                        $rows[] = ['agency_id' => $agencyId, 'user_id' => $uid, 'type' => 'break_overrun', 'title' => $title, 'body' => $body,
                            'data' => json_encode(['link' => '#time-clock']), 'created_at' => now()];
                    }
                    Notify::write($rows);
                    try {
                        \App\Support\Audit::write([
                            'user_id' => null, 'agency_id' => $agencyId, 'action' => 'staff.break_overrun',
                            'entity_type' => 'user', 'entity_id' => (int) $b->user_id,
                            'payload' => json_encode(['summary' => "{$name}'s {$what} ran over ({$mins} min, limit {$limit}); "
                                . count($ids) . ' director(s)/admin(s) alerted.', 'break_id' => (int) $b->id]),
                            'created_at' => now(),
                        ]);
                    } catch (\Throwable $e) {
                    }
                }
                $sent++;
            }
        }
        if ($sent || $dry) {
            $this->info(($dry ? '[dry] ' : '') . "{$sent} overrun notice(s)");
        }

        return self::SUCCESS;
    }

    /** Take the step for this break; false if another run already did. A dry run claims nothing. */
    private function claim(int $breakId, string $col, bool $dry): bool
    {
        if ($dry) {
            return true;
        }

        return DB::table('time_punch_breaks')->where('id', $breakId)->whereNull($col)
            ->update([$col => now(), 'updated_at' => now()]) === 1;
    }

    /** The centre's directors and/or the agency's admins (as the agency chose), never the person on break. */
    private function managers(int $agencyId, int $centreId, int $self, array $s): array
    {
        $ids = [];
        if ($s['overrun_notify_directors'] && $centreId) {
            $ids = array_merge($ids, DB::table('role_assignments')->where('active', 1)->where('role', 'centre_director')
                ->where('centre_id', $centreId)->pluck('user_id')->all());
        }
        if ($s['overrun_notify_admins']) {
            $ids = array_merge($ids, DB::table('role_assignments')->where('active', 1)->where('role', 'agency_admin')
                ->where('agency_id', $agencyId)->pluck('user_id')->all());
        }
        $ids = array_values(array_diff(array_unique(array_map('intval', $ids)), [$self]));
        if (! $ids) {
            return [];
        }

        return DB::table('users')->whereIn('id', $ids)->whereNull('deleted_at')->where('status', 'active')
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
    }
}
