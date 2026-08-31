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

// End-of-day summary to PARENTS — 18:30, after pickup. One email per child:
// the AI story of the day, photos, sign-in/out, every care log, the day's
// messages, and any announcements. Each child is bucketed in its own agency's
// timezone inside the command, so this schedule only picks the wall-clock hour.
// Closures: announced once when entered, which for a holiday added months ahead is
// long forgotten by the time it matters. Reminds a week out and the day before.
Schedule::command('closures:remind')->dailyAt('11:30')->withoutOverlapping();

Schedule::command("kiddietrac:educator-summary")->dailyAt("19:00")->timezone("America/Toronto");
Schedule::command('kiddietrac:parent-summary')
    ->dailyAt('18:30')
    ->timezone('America/Toronto')
    ->withoutOverlapping(60)
    ->runInBackground()
    ->onOneServer();

// Attendance reminders. Morning: the child has no sign-in and no reported absence.
// Evening: the child was signed in and never signed out (an open attendance record
// has to be closed by hand otherwise). Parents who reported an absence are skipped.
Schedule::command('kiddietrac:checkin-reminders --window=morning')
    ->dailyAt('09:30')
    ->timezone('America/Toronto')
    ->weekdays()
    ->withoutOverlapping(30)
    ->runInBackground()
    ->onOneServer();

Schedule::command('kiddietrac:checkin-reminders --window=evening')
    ->dailyAt('18:00')
    ->timezone('America/Toronto')
    ->weekdays()
    ->withoutOverlapping(30)
    ->runInBackground()
    ->onOneServer();

// Daily morning digest — 07:00 in the agency's timezone (Toronto default).
Schedule::command('kiddietrac:apply-withdrawals')->dailyAt('01:00');

/* Complete family de-enrolments whose agreed last day has passed. Just after the child
   withdrawals that use the same pattern, and early enough that a departed family is
   closed before anybody opens a roster. */
Schedule::command('families:apply-departures')->dailyAt('01:15')->timezone('America/Toronto')->withoutOverlapping();

/* Point each multi-provider child's primary room at whoever has them today, before
   anybody opens a roster. Half the portal reads that single column, so for a child who
   is with one provider Mon-Thu and another on Friday it has to be refreshed daily.
   Early enough to be done before the first check-in, and idempotent. */
Schedule::command('care:sync-rooms')->dailyAt('04:15')->timezone('America/Toronto')->withoutOverlapping();

// RETIRED 18 Aug 2026 — replaced by kiddietrac:admin-digest below, which covers the
// same audience (agency admins and centre directors) with the decisions-to-make
// format. Left runnable by hand rather than deleted.
// Schedule::command('kiddietrac:digest-daily')
//     ->dailyAt('07:00')
//     ->timezone('America/Toronto')
//     ->withoutOverlapping(60)        // skip if a prior run is still going
//     ->runInBackground()
//     ->onOneServer();                // safe even on a single host

// Weekly Monday digest — 07:05 so it never collides with the daily run.
// RETIRED 18 Aug 2026 — replaced by kiddietrac:admin-digest below, which covers the
// same audience (agency admins and centre directors) with the decisions-to-make
// format. Left runnable by hand rather than deleted.
// Schedule::command('kiddietrac:digest-weekly')
//     ->weeklyOn(1, '07:05')          // 1 = Monday
//     ->timezone('America/Toronto')
//     ->withoutOverlapping(60)
//     ->runInBackground()
//     ->onOneServer();

// v22p38: marketing-campaign email sender — every 5 min so scheduled
// campaigns are delivered within ~5 min of their scheduled_for. In-portal
// 'both' campaigns get their email follow-up on the same tick.
Schedule::command('kiddietrac:campaigns-mail')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onOneServer();

/* Switched-off accounts lose their API tokens within 15 minutes, whichever path set
   the status. Deactivating used to block only NEW logins. */
Schedule::command('kiddietrac:revoke-off-tokens')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->onOneServer();

// v22p40: missed-chat email notifications — every 15 minutes. Picks up
// messages older than 30 minutes that have not been read and not yet
// emailed. Groups by recipient+conversation so each user gets ONE
// summary email per thread (not one per message).
/* PAUSED 2026-08-20 — this grouped by recipient+CONVERSATION, so a person in 39
   threads received 39 separate emails from one run (144 emails in a single run,
   39 into one inbox in one minute). Re-enabled once it sends ONE digest per person. */
Schedule::command('kiddietrac:chat-emails')
    ->everyFifteenMinutes()
    ->skip(fn () => ! config('suppression.chat_emails_enabled', false))
    ->withoutOverlapping(20)
    ->runInBackground()
    ->onOneServer();

/* Apply late fees once per day at 02:00 Toronto time. Idempotent per
   (invoice, month) so a missed run catches up on the next day with no duplicates.

   THE NAME WAS WRONG AND HAD ALWAYS BEEN WRONG (fixed 2026-08-31, ticket #27).
   This said 'kiddietrac:late-fees'; the command has been 'invoices:apply-late-fees'
   since v22p51, when it was rewritten for per-agency config. The schedule entry was
   never updated, so every night at 02:00 the scheduler threw CommandNotFoundException
   and no late fee has EVER been applied by cron — there is not one late_fee line in
   invoice_lines to this day. Nothing alerted anybody: the exception went to
   scheduler.log, which had grown to 51MB of it.

   IT STAYS OFF UNTIL SOMEBODY DECIDES OTHERWISE. Correcting the name alone would have
   started charging 1.5% monthly interest to every agency the very next night, because
   agencies.late_fee_percent DEFAULTS to 1.50 — no agency has ever opted in, they simply
   inherit the column default. Charging families money is not a side effect a bug fix
   gets to have, so this follows billing.reminders_enabled: a platform-wide gate, off by
   default, deliberately turned on once. Set LATE_FEES_ENABLED=true in .env when the
   decision has been made and per-agency percentages have been reviewed. */
Schedule::command('invoices:apply-late-fees')
    ->dailyAt('02:00')
    ->timezone('America/Toronto')
    ->skip(fn () => ! config('billing.late_fees_enabled', false))
    ->withoutOverlapping(60)
    ->runInBackground()
    ->onOneServer();

// v22p51 — append these inside the closure in routes/console.php

Schedule::command('invoices:autopay-charge')->dailyAt('03:00');
Schedule::command('expiry:warn')->dailyAt('08:00');
Schedule::command('demo:seed-daily')->dailyAt('05:00')->withoutOverlapping();


Schedule::command('drip:dispatch')->hourly();
Schedule::command('portfolio:year-end')->yearlyOn(12, 15, '09:00');

// Mail goes out half an hour before the in-app bells, so the email about a birthday
// arrives before the notification about it. Toronto explicitly: a birthday greeting
// timed by a UTC server lands in the middle of the night.
// Hourly, not once a night: the job only closes what is already past its agency's
// sign-off time, and running often means a forgotten punch is corrected while the day
// it belongs to is still recent enough for somebody to query it.
Schedule::command('kiddietrac:auto-signoff')->hourly()->withoutOverlapping();

/* Delivery failures. Until this existed, email_logs said 'sent' whether the
   message arrived or was rejected outright -- bounces piled up unread in the
   server mailbox and nothing in the portal ever knew. */
Schedule::command('mail:ingest-bounces')->hourly()->withoutOverlapping();
Schedule::command('kiddietrac:birthday-emails')->dailyAt('07:00')->timezone('America/Toronto');
Schedule::command('birthdays:celebrate')->dailyAt('07:30');

// v22p98 — staff time-clock reminders (compliance/payroll/reporting)
// Hourly, not two fixed times: each agency sets its own reminder hours in Settings ->
// Clock settings, and the command skips any agency whose hour is not the current one in
// ITS timezone. A centre opening at 06:00 was previously nudged at 10:00 like everybody
// else. Weekend skipping is per agency now too, so the schedule no longer assumes it.
Schedule::command('staff:clock-reminders --mode=all --scheduled')->hourly()->withoutOverlapping();

// Weekly portal tips — a rotating "did you know?" push to parents + educators
// (APK notification + in-app bell). Wednesday mid-morning for good engagement.
Schedule::command('kiddietrac:portal-tips')->weeklyOn(3, '10:00')->withoutOverlapping();

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


// Daily sales-lead follow-up reminders (email owner/superadmin about due/overdue follow-ups).
Schedule::command('kiddietrac:sales-followups')->dailyAt('08:00');

// Nightly data-retention purge — enforces each agency's compliance policy
// (chat + announcements past their retention window). Opt-in per agency via
// Settings → Data Retention (auto_enforce). Off-hours so it never contends.
Schedule::command('retention:purge')->dailyAt('02:30')->withoutOverlapping();

// Scheduled reports — email due canned-report schedules hourly (fires once/day at/after each schedule send-time hour).
Schedule::command("kiddietrac:send-scheduled-reports")->hourly()->withoutOverlapping();

// Any sender that sets X-KT-Logged without writing its own email_logs row leaves an
// email delivered but invisible in the log. The email.sent audit rows are the
// independent witness, so this reconciles the two nightly and backfills the gap,
// marking such rows "reconstructed from audit trail" so the omission stays visible.
Schedule::command("mail:reconcile-logs --days=3 --backfill")->dailyAt("06:15")->timezone("America/Toronto");

// Invited-but-not-onboarded nudges. The command self-gates to each agency's configured
// hour (default 07:00 ET) and caps itself at 4 reminders, >= 3 days apart, per invitee,
// so hourly dispatch is the intended cadence rather than hourly mail. This was never
// registered anywhere - in practice NOBODY was being reminded.
Schedule::command("kiddietrac:onboarding-reminders")->hourly();


// The director / admin digest: what needs a decision today, and a wider view on Monday.
// 07:00 and 07:10 Toronto — before the day starts, and far enough apart that a slow
// weekly run cannot delay the daily one.
Schedule::command('kiddietrac:admin-digest')->dailyAt('07:00')->timezone('America/Toronto')->withoutOverlapping();
Schedule::command('kiddietrac:admin-digest --weekly')->weeklyOn(1, '07:10')->timezone('America/Toronto')->withoutOverlapping();

/* Nightly billing. Installed but INERT: the command returns immediately unless
   billing.auto_raise is true in platform_settings, and refuses to email while
   invoice.issuer.address / tax_id are unset. Scheduling it costs nothing until
   those are deliberately turned on. */
Schedule::command('platform:billing-run')->dailyAt('06:00')->withoutOverlapping();
