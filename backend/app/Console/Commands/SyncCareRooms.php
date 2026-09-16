<?php

namespace App\Console\Commands;

use App\Support\CareSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Point every child's `primary_room_id` at whichever provider has them TODAY.
 *
 * Half the portal reads that one column — the educator's own room list, ratios, the day
 * brief — and it can only hold a single value, so for a child who splits their week it
 * has to mean "where they are right now" rather than "where they belong". Rewriting all
 * of those reads to be schedule-aware would be a far larger change than the problem
 * warrants; refreshing the column each morning gets the same answer.
 *
 * Runs early, before anyone opens a roster. Safe to run repeatedly: it only writes when
 * the room actually differs, and it never blanks a child who is not booked today.
 */
class SyncCareRooms extends Command
{
    protected $signature = 'care:sync-rooms {--dry : Report what would change without writing}';

    protected $description = "Set each child's primary room to today's provider from their care schedule";

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');

        /* Only children who actually split their week. Everybody else has one open
           enrolment and one room, so there is nothing to decide and no reason to touch
           thousands of rows every morning. */
        $childIds = DB::table('enrollments')
            ->whereNull('end_date')
            ->select('child_id')
            ->groupBy('child_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('child_id');

        if ($childIds->isEmpty()) {
            $this->info('No children have more than one provider — nothing to sync.');

            return self::SUCCESS;
        }

        $changed = 0;
        foreach ($childIds as $cid) {
            $child = DB::table('children')->find($cid);
            if (! $child || $child->enrollment_status !== 'enrolled' || $child->deleted_at) {
                continue;
            }

            $tz = CareSchedule::tzForChild((int) $cid);
            $want = CareSchedule::roomToday((int) $cid, $tz);
            if (! $want || (int) $child->primary_room_id === (int) $want) {
                continue;
            }

            $from = DB::table('rooms')->where('id', $child->primary_room_id)->value('name') ?: '(none)';
            $to = DB::table('rooms')->where('id', $want)->value('name');
            $this->line(sprintf('  %-22s %s → %s',
                trim($child->first_name.' '.$child->last_name), $from, $to));

            if (! $dry) {
                CareSchedule::syncPrimaryRoom((int) $cid, $tz);
            }
            $changed++;
        }

        $this->info(($dry ? 'Would move ' : 'Moved ').$changed.' of '.$childIds->count()
            .' multi-provider children to today\'s room.');

        return self::SUCCESS;
    }
}
