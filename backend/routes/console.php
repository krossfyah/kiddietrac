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


Schedule::command('drip:dispatch')->hourly();
Schedule::command('portfolio:year-end')->yearlyOn(12, 15, '09:00');
