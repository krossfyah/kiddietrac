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
