<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * THE EVERY-MINUTE JOBS IN ONE PROCESS (2026-11-10, Anthony: "full sweep throughout to see why portal is generally moving
 * slower"). Ten jobs ran every minute as their own `php artisan` process, eight of them in the background with a second
 * process for schedule:finish: 22 PHP start-ups a minute at ~0.6 CPU-seconds each before any work, all on the minute.
 * On two cores that made the scheduler use more CPU than every web request together, and pages opened in the first 20
 * seconds of each minute took 3-4x as long. Each group below is ONE process that runs its jobs in turn, already booted.
 *
 * Two groups so a slow mail or sales run can never hold back a child-safety alert: `safety` (sleep checks, pickup
 * alarms, Safe Arrival, break overrun, missed-call alerts) and `work`. Each job keeps its own overlap lock (the minutes
 * it had before), one job failing is reported and the rest still run, and a job over 20 s is logged so a slow one shows.
 * The queue worker stays its own process (it waits for jobs). Add a new every-minute job HERE, not in routes/console.php.
 */
class MinuteBatchCommand extends Command
{
    protected $signature = 'kt:minute {group : safety, work, two, five, ten or fifteen}';
    protected $description = 'Run one group of the frequent scheduled jobs in a single process';

    /** command => overlap lock in minutes (what its own withoutOverlapping() was). */
    public const GROUPS = [
        'safety' => [
            'sleep-checks:alert' => 5,
            'parents:pickup-alarms' => 5,
            'kiddietrac:safe-arrival' => 10,
            'kiddietrac:safe-arrival-next' => 3,
            'staff:break-overrun' => 5,
            'kt:call-alerts' => 5,
        ],
        'work' => [
            'kiddietrac:ref-codes' => 5,
            'announcements:send-scheduled' => 5,
            'kiddietrac:mail-watch' => 5,
            'kiddietrac:sales-campaigns' => 10,
        ],
        /* The every-2/5/10/15-minute jobs the same way (2026-11-10, "yes"): each group is one process on its own tick.
           Safety first within a group. Left as their own entries on purpose: chat-emails (its on/off switch),
           check-ratios (daytime only), call-transcripts and the Xero/QuickBooks/Sage syncs (slow outside calls). */
        'two' => [   // everyTwoMinutes
            'kt:personal-announcements' => 5,
            'kt:payslip-notices' => 10,
            'hr:shift-notices' => 5,   // KiddieTrac HR: one notice per person per quiet batch of shift changes (2026-11-27)
            'kt:walkie-sweep' => 3,   // 📻 walkie-talkie: end silent talks, delete live pieces, retention (2026-11-27)
        ],
        'five' => [   // everyFiveMinutes
            'headcounts:remind' => 10,
            'headcounts:strikes' => 10,
            'staff:empty-room-clock-out' => 5,
            'kiddietrac:roster-alerts' => 10,
            'drills:educator-schedule' => 4,
            'walks:nudge-quiet' => 10,
            'kiddietrac:task-reminders' => 10,
            'rsvp:send-reminders' => 10,
            'kt:sales-meetings' => 10,
            'kt:server-health-sample' => 10,
            'kiddietrac:campaigns-mail' => 10,
            'kiddietrac:nps-surveys' => 10,   // NPS: pending invites; due rounds once a day (2026-11-28)
        ],
        'ten' => [   // everyTenMinutes
            'kiddietrac:educator-summary --due' => 30,
            'kiddietrac:parent-summary --due' => 30,
            'kt:encrypt-sensitive' => 10,
            'kt:calendar-responses' => 10,   // answers from connected Outlook / Google calendars (2026-11-25)
        ],
        'fifteen' => [   // everyFifteenMinutes
            'kiddietrac:revoke-off-tokens' => 10,
            'security:alerts' => 14,
            'timeoff:remind-approvers' => 14,
            'forms:remind-countersign' => 14,
            'kt:countersign-finish' => 30,
            'providers:remind-bio' => 14,
            'drills:remind' => 14,
            'records:remind' => 14,
            'kt:ticket-sla' => 10,
            'kt:thank-you-awards' => 30,
            'kt:mail-gate-watch --hours=2 --fix' => 14,
            'kt:cold-reach' => 14,
        ],
    ];

    public function handle(): int
    {
        $jobs = self::GROUPS[(string) $this->argument('group')] ?? null;
        if ($jobs === null) {
            $this->error('Unknown group - use: ' . implode(', ', array_keys(self::GROUPS)));
            return self::FAILURE;
        }
        foreach ($jobs as $cmd => $minutes) {
            $lock = Cache::lock('kt-minute:' . $cmd, $minutes * 60);
            if (! $lock->get()) continue;   // still running from an earlier minute
            $t = microtime(true);
            try {
                Artisan::call($cmd);
            } catch (\Throwable $e) {
                report($e);
            } finally {
                $lock->release();
            }
            $secs = microtime(true) - $t;
            if ($secs > 20) Log::warning('kt:minute slow job', ['job' => $cmd, 'seconds' => round($secs, 1)]);
            // When each job last ran and how long it took (kt-minute:last:<job>), so a stalled one can be seen.
            Cache::put('kt-minute:last:' . $cmd, ['at' => now()->utc()->toDateTimeString(), 'secs' => round($secs, 2), 'ok' => ! isset($e)], 3600);
            unset($e);
        }

        return self::SUCCESS;
    }
}
