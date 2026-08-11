<?php

namespace App\Console;

use App\Console\Commands\GenerateDailyDigests;
use App\Console\Commands\GenerateMonthlyInvoices;
use App\Console\Commands\SendCertificationReminders;
use App\Console\Commands\CheckRatioViolations;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        GenerateDailyDigests::class,
        GenerateMonthlyInvoices::class,
        SendCertificationReminders::class,
        CheckRatioViolations::class,
    ];

    /**
     * The Kiddietrac automated schedule.
     * Run via: php artisan schedule:run (every minute from cron)
     */
    protected function schedule(Schedule $schedule): void
    {
        // NOTE: the database-queue worker that drains ->queue() mail (invite /
        // welcome / password-reset / onboarding-confirmation via AccountNotice) is
        // scheduled in routes/console.php, so it is intentionally NOT duplicated
        // here (two definitions would double-process the same jobs).

        // ─── Mail health guard ──────────────────────────────
        // Daily: verify Microsoft Graph auth works and warn superadmins BEFORE the
        // client secret expires. (Sending already fails over to sendmail, so this
        // is the "rotate the secret in time" alert, not a delivery dependency.)
        $schedule->command('kiddietrac:mail-health')
            ->dailyAt('07:30')
            ->timezone('America/Toronto')
            ->withoutOverlapping();

        // ─── Daily digests ──────────────────────────────────
        // Runs at 6 PM in centre's timezone. Generates AI summary for every
        // enrolled child based on the day's logged events.
        $schedule->command('kiddietrac:generate-digests')
            ->dailyAt('18:00')
            ->timezone('America/Toronto')
            ->withoutOverlapping()
            ->runInBackground()
            ->emailOutputOnFailure('ops@kiddietrac.ca');

        // ─── Monthly invoices ───────────────────────────────
        // 1st of every month at 6 AM. Generates invoices for all families,
        // applies CWELCC subsidy, sends payment reminder emails.
        $schedule->command('kiddietrac:generate-invoices')
            ->monthlyOn(1, '06:00')
            ->timezone('America/Toronto')
            ->emailOutputOnFailure('ops@kiddietrac.ca');

        // ─── Certification expiry reminders ────────────────
        // Every Monday morning, scan for First Aid/CPR/VSC expiring within 60 days.
        $schedule->command('kiddietrac:cert-reminders')
            ->mondays()
            ->at('08:00')
            ->timezone('America/Toronto');

        // ─── Onboarding reminders ────────────────────────────
        // Nudge users who were invited but never set up their account
        // (onboarded_at IS NULL). White-labelled per agency; exempt from the
        // not-onboarded gate via X-KT-Invite so it actually reaches them.
        // Runs HOURLY — the command self-gates per agency by their configured
        // send-hour (default 07:00 ET) + enabled flag (default ON), both editable
        // in the portal under Email settings.
        $schedule->command('kiddietrac:onboarding-reminders')
            ->hourly()
            ->timezone('America/Toronto');

        // ─── Ratio violation check ──────────────────────────
        // Every 15 minutes during centre hours, detect rooms in breach.
        $schedule->command('kiddietrac:check-ratios')
            ->everyFifteenMinutes()
            ->between('06:00', '19:00')
            ->timezone('America/Toronto');

        // ─── Missed chat-message email escalation ───────────
        // Every 5 min, email the intended recipients about chat messages that have
        // been left unread for 15+ minutes (each message emailed at most once).
        $schedule->command('kiddietrac:missed-messages --minutes=15')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        // ─── Cleanup expired tokens ─────────────────────────
        $schedule->command('sanctum:prune-expired --hours=24')
            ->daily();

        // ─── Database backup ────────────────────────────────
        // We use the bash script kt-backup.sh for the actual mysqldump.
        // This just verifies the latest backup is fresh.
        $schedule->call(function () {
            $latest = glob('/var/backups/kiddietrac/db_*.sql.gz');
            if (empty($latest)) {
                \Log::error('No database backups found!');
            } else {
                $newest = max(array_map('filemtime', $latest));
                if (time() - $newest > 60 * 60 * 26) {
                    \Log::error('Most recent backup is older than 26 hours');
                }
            }
        })->dailyAt('05:00');
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}
