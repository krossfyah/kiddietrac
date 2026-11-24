<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * walks:nudge-quiet — "Is your walk over?" (2026-10-28, Anthony: "with educators on a walk and for whatever reason
 * it loses connection can you resume and make sure educator is prompted if walk is over").
 *
 * A live walk that has sent no location for QUIET_MIN minutes is either out of signal, on a phone whose app was
 * closed, or finished and never ended. The app itself prompts whenever it is open (kt-walk-tracker.js); this reaches
 * the educator when it is NOT open: one bell and one push to the walk's lead, at most once an hour per walk. It
 * never ends a walk and never tells parents - only the educator knows whether the children are back.
 */
class NudgeQuietWalksCommand extends Command
{
    protected $signature = 'walks:nudge-quiet {--dry-run : List who would be asked}';

    protected $description = 'Ask the educator when a live walk has gone quiet';

    private const QUIET_MIN = 20;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $cut = now()->subMinutes(self::QUIET_MIN);
        $walks = DB::table('field_trips')->where('status', 'active')->whereNotNull('staff_lead_id')
            ->where('created_at', '<=', $cut)->get(['id', 'staff_lead_id', 'destination', 'title', 'created_at']);
        $n = 0;
        foreach ($walks as $w) {
            $last = DB::table('field_trip_pings')->where('field_trip_id', $w->id)->max('recorded_at');
            if ($last && Carbon::parse((string) $last)->gt($cut)) {
                continue;   // still reporting
            }
            $key = 'walk-nudge:' . $w->id;
            if (Cache::has($key)) {
                continue;   // asked within the hour
            }
            $mins = (int) Carbon::parse((string) ($last ?: $w->created_at))->diffInMinutes(now());
            $where = trim((string) ($w->destination ?: $w->title ?: 'your walk'));
            $title = 'Is your walk over?';
            $body = 'No location from your walk to ' . $where . ' for ' . $mins . ' minutes. Open KiddieTrac to resume sharing, or end the walk if everyone is back.';
            $this->line(($dry ? '  would ask' : '  asked') . ' user ' . $w->staff_lead_id . ' about walk ' . $w->id . ' (' . $mins . ' min quiet)');
            if ($dry) {
                continue;
            }
            Cache::put($key, 1, now()->addHour());
            try {
                DB::table('notifications')->insert([
                    'user_id' => (int) $w->staff_lead_id, 'type' => 'walk_quiet', 'title' => $title, 'body' => $body,
                    'data' => json_encode(['link' => '#walk', 'trip_id' => (int) $w->id]), 'created_at' => now(),
                ]);
            } catch (\Throwable $e) { report($e); }
            try {
                app(\App\Services\FcmService::class)->sendToUser((int) $w->staff_lead_id, $title, $body, '#walk');
            } catch (\Throwable $e) { /* no app on this phone: the bell is enough */ }
            $n++;
        }
        $this->info(($dry ? 'Would ask ' : 'Asked ') . $n . ' educator(s).');

        return self::SUCCESS;
    }
}
