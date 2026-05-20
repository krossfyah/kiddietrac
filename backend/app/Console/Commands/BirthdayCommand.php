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
 * Scheduled: daily at 07:30.
 */
final class BirthdayCommand extends Command
{
    protected $signature = 'birthdays:celebrate {--dry-run}';
    protected $description = 'Fire birthday notifications for every child whose birthday is today';

    public function handle(): int
    {
        $today = Carbon::today();
        $year = $today->year;
        $mmdd = $today->format('m-d');

        $children = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('ch.enrollment_status', 'enrolled')
            ->whereNull('ch.deleted_at')
            ->whereRaw("DATE_FORMAT(ch.date_of_birth, '%m-%d') = ?", [$mmdd])
            ->select('ch.id', 'ch.first_name', 'ch.last_name', 'ch.date_of_birth',
                'ch.family_id', 'f.centre_id', 'c.name as centre_name')
            ->get();

        $this->info("Found {$children->count()} birthday(s) today");
        $celebrated = 0;
        foreach ($children as $ch) {
            $already = DB::table('birthday_celebrations')
                ->where('child_id', $ch->id)->where('birthday_year', $year)->exists();
            if ($already) continue;
            $turning = Carbon::parse($ch->date_of_birth)->diffInYears($today);
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
                DB::table('notifications')->insert([
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
                DB::table('notifications')->insert([
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
