<?php
declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p59 — Daily birthday celebration automation.
 *  - Inserts a birthday_celebrations row per child whose birthday is today
 *  - Notifications fan out to family + centre staff
 *  - Idempotent via UNIQUE (child_id, birthday_year)
 *
 * Scheduled: daily at 07:30. "Today" is each agency's own day (AgencyTime::tz).
 */
final class BirthdayCommand extends Command
{
    protected $signature = 'birthdays:celebrate {--dry-run}';
    protected $description = 'Fire birthday notifications for every child whose birthday is today';

    public function handle(): int
    {
        /* Today in EACH agency's zone (2026-10-21), not the server's UTC day: whose birthday
           it is depends on where the child is. Candidates are fetched for every date that is
           "today" somewhere (at most two), then kept only if it is today in their agency. */
        $tzToday = [];
        foreach (DB::table('agencies')->pluck('id') as $aid) {
            $tzToday[(int) $aid] = Carbon::now(\App\Support\AgencyTime::tz((int) $aid))->startOfDay();
        }
        $mmdds = array_values(array_unique(array_map(fn ($d) => $d->format('m-d'), $tzToday)));

        $children = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->tap(fn ($q) => \App\Support\Live::centres(\App\Support\Live::families($q, 'f'), 'c'))
            ->where('ch.enrollment_status', 'enrolled')
            ->whereNull('ch.deleted_at')
            ->whereRaw("DATE_FORMAT(ch.date_of_birth, '%m-%d') IN (" . implode(',', array_fill(0, count($mmdds), '?')) . ')', $mmdds)
            ->select('ch.id', 'ch.first_name', 'ch.last_name', 'ch.date_of_birth',
                'ch.family_id', 'f.centre_id', 'c.name as centre_name', 'c.agency_id')
            ->get()
            ->filter(function ($ch) use ($tzToday) {
                $t = $tzToday[(int) $ch->agency_id] ?? null;
                return $t && substr((string) $ch->date_of_birth, 5, 5) === $t->format('m-d');
            })
            ->values();

        $this->info("Found {$children->count()} birthday(s) today");
        $celebrated = 0;
        foreach ($children as $ch) {
            $today = $tzToday[(int) $ch->agency_id];
            $year = $today->year;
            $already = DB::table('birthday_celebrations')
                ->where('child_id', $ch->id)->where('birthday_year', $year)->exists();
            if ($already) continue;
            // Whole years on the agency's date (Carbon 3 returns a float here: "turning 4.0003").
            $turning = (int) $today->year - (int) substr((string) $ch->date_of_birth, 0, 4);
            if ($this->option('dry-run')) {
                $this->line("  would celebrate: {$ch->first_name} (turning {$turning})");
                continue;
            }
            DB::table('birthday_celebrations')->insert([
                'child_id' => $ch->id,
                'birthday_year' => $year,
                'celebrated_at' => now(),
                'notification_sent' => 1,
                'notes' => "Turning {$turning} on " . $today->format('M j'),
            ]);
            // Notify guardians
            $gids = DB::table('guardians')->where('family_id', $ch->family_id)->pluck('user_id');
            foreach ($gids as $gid) {
                \App\Support\Notify::write([
                    'agency_id' => \App\Support\Notify::agencyOfChild((int) $ch->id),
                    'user_id' => $gid, 'type' => 'birthday',
                    'title' => "Happy birthday {$ch->first_name}! 🎉",
                    'body' => "{$ch->first_name} is turning {$turning} today. Your centre is celebrating!",
                    'data' => json_encode(['link' => '#today', 'child_id' => $ch->id]),
                    'created_at' => now(),
                ]);
            }
            // Notify centre staff
            $staffIds = DB::table('role_assignments')->where('centre_id', $ch->centre_id)
                ->whereIn('role', ['educator', 'centre_director'])->where('active', 1)
                ->pluck('user_id')->unique();
            foreach ($staffIds as $sid) {
                \App\Support\Notify::write([
                    'agency_id' => \App\Support\Notify::agencyOfChild((int) $ch->id),
                    'user_id' => $sid, 'type' => 'birthday',
                    'title' => "🎂 {$ch->first_name}'s birthday today",
                    'body' => "Turning {$turning} · {$ch->centre_name}",
                    'data' => json_encode(['link' => '#today', 'child_id' => $ch->id]),
                    'created_at' => now(),
                ]);
            }
            $celebrated++;
        }
        $this->info("Birthdays celebrated: {$celebrated}");
        return 0;
    }
}
