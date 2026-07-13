<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| v22p36 — Scheduled tasks
|--------------------------------------------------------------------------
|
| All tasks below run via the cPanel cron entry that fires
| `php artisan schedule:run` every minute. See the v22p36 commit message
| for the cron line that was installed.
|
*/

// Daily morning digest — 07:00 in the agency's timezone (Toronto default).
Schedule::command('kiddietrac:digest-daily')
    ->dailyAt('07:00')
    ->timezone('America/Toronto')
    ->withoutOverlapping(60)        // skip if a prior run is still going
    ->runInBackground()
    ->onOneServer();                // safe even on a single host

// Weekly Monday digest — 07:05 so it never collides with the daily run.
Schedule::command('kiddietrac:digest-weekly')
    ->weeklyOn(1, '07:05')          // 1 = Monday
    ->timezone('America/Toronto')
    ->withoutOverlapping(60)
    ->runInBackground()
    ->onOneServer();

// v22p38: marketing-campaign email sender — every 5 min so scheduled
// campaigns are delivered within ~5 min of their scheduled_for. In-portal
// 'both' campaigns get their email follow-up on the same tick.
Schedule::command('kiddietrac:campaigns-mail')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onOneServer();

// v22p40: missed-chat email notifications — every 15 minutes. Picks up
// messages older than 30 minutes that have not been read and not yet
// emailed. Groups by recipient+conversation so each user gets ONE
// summary email per thread (not one per message).
Schedule::command('kiddietrac:chat-emails')
    ->everyFifteenMinutes()
    ->withoutOverlapping(20)
    ->runInBackground()
    ->onOneServer();

// v22p49 — apply late fees once per day at 02:00 Toronto time. Idempotent
// per (invoice, period) so a missed run catches up on the next day with
// no duplicates.
Schedule::command('kiddietrac:late-fees')
    ->dailyAt('02:00')
    ->timezone('America/Toronto')
    ->withoutOverlapping(60)
    ->runInBackground()
    ->onOneServer();

// v22p51 — append these inside the closure in routes/console.php

Schedule::command('invoices:autopay-charge')->dailyAt('03:00');
Schedule::command('expiry:warn')->dailyAt('08:00');
Schedule::command('demo:seed-daily')->dailyAt('05:00')->withoutOverlapping();


Schedule::command('drip:dispatch')->hourly();
Schedule::command('portfolio:year-end')->yearlyOn(12, 15, '09:00');

Schedule::command('birthdays:celebrate')->dailyAt('07:30');

// v22p98 — staff time-clock reminders (compliance/payroll/reporting)
Schedule::command('staff:clock-reminders --mode=clock_in')->weekdays()->dailyAt('10:00');
Schedule::command('staff:clock-reminders --mode=clock_out')->weekdays()->dailyAt('18:30');

// SOC 2 — security monitoring: scan the audit log for auth anomalies.
Schedule::command('security:alerts')->everyFifteenMinutes()->withoutOverlapping();

// SOC 2 — Availability: nightly verified database backup (retains 14 days).
Schedule::command('db:backup')->dailyAt('03:30')->withoutOverlapping();

// Invoice/payment reminders — hourly; gated by config('billing.reminders_enabled')
// (OFF) + per-agency settings; only fires in each agency's send-time hour.
Schedule::command('billing:reminders')->hourly()->withoutOverlapping();

// Async email/queue worker — drains queued jobs (invite emails, announcements,
// bulk sends) in the background so admin actions return instantly instead of
// blocking ~1.3–5.5s per email. --stop-when-empty exits when drained; --max-time
// keeps each run under a minute so the next tick restarts it.
Schedule::command("queue:work --queue=mail,default --stop-when-empty --max-time=55 --tries=3 --sleep=2 --backoff=30")
    ->everyMinute()->withoutOverlapping()->runInBackground();
