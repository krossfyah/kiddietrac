<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\TrainingMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Training reminders (2026-10-02), daily.
 *
 *   due soon — the due date is within the next 2 days and it is not watched yet;
 *   overdue  — the due date has passed and it is not watched yet.
 *
 * Each is sent once per assignment (reminded_due_at / reminded_overdue_at), grouped
 * into one email per person, and never for anything already completed or cancelled.
 *
 *   php artisan training:remind [--dry-run]
 */
class TrainingRemind extends Command
{
    protected $signature = 'training:remind {--dry-run : list, send nothing}';

    protected $description = 'Email training reminders: due within 2 days, and overdue';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $today = now()->toDateString();
        $soon = now()->addDays(2)->toDateString();

        $open = DB::table('training_assignments as ta')
            ->join('training_videos as v', 'v.id', '=', 'ta.video_id')
            ->leftJoin('training_progress as p', function ($j) { $j->on('p.video_id', '=', 'ta.video_id')->on('p.user_id', '=', 'ta.user_id'); })
            ->whereNull('ta.cancelled_at')->whereNotNull('ta.due_on')->whereNull('p.completed_at')->where('v.status', 'published')
            ->get(['ta.id', 'ta.agency_id', 'ta.user_id', 'ta.due_on', 'ta.reminded_due_at', 'ta.reminded_overdue_at', 'v.title']);

        foreach (['overdue', 'due_soon'] as $kind) {
            $pick = $open->filter(fn ($a) => $kind === 'overdue'
                ? ($a->due_on < $today && ! $a->reminded_overdue_at)
                : ($a->due_on >= $today && $a->due_on <= $soon && ! $a->reminded_due_at));
            foreach ($pick->groupBy(fn ($a) => $a->agency_id . ':' . $a->user_id) as $rows) {
                $first = $rows->first();
                $items = $rows->map(fn ($a) => ['title' => $a->title, 'due_on' => $a->due_on])->values()->all();
                $this->line(($dry ? '[dry] ' : '') . "$kind → user {$first->user_id}: " . count($items) . ' video(s)');
                if ($dry) {
                    continue;
                }
                if (TrainingMail::send((int) $first->agency_id, (int) $first->user_id, $kind, $items)) {
                    DB::table('training_assignments')->whereIn('id', $rows->pluck('id'))
                        ->update([$kind === 'overdue' ? 'reminded_overdue_at' : 'reminded_due_at' => now()]);
                }
            }
        }

        return self::SUCCESS;
    }
}
