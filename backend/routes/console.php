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
/* Generate statutory holiday closures ahead of the reminder pass, so a holiday added by
   the rules today can still be announced by closures:remind at 11:30. Weekly is plenty --
   the rules change once a year, not once a day -- but it runs cheaply and idempotently. */
Schedule::command('holidays:sync --months=14')
    ->weeklyOn(1, '03:30')->timezone('America/Toronto')->withoutOverlapping();

Schedule::command('closures:remind')->dailyAt('11:30')->withoutOverlapping();

/* Event RSVP reminders (2026-10-06): sends any reminder whose time has come — "1 day
   before" the event, or a set date and time. Every five minutes, so one goes out within
   five minutes of its time; each is claimed before sending, so overlapping runs cannot
   send it twice. */
// everyFiveMinutes in kt:minute five (MinuteBatchCommand) - one process for the group.
// Time-off approval reminders: each agency's own schedule (Settings -> Clock settings ->
// Time-off approvals) decides when its digest is due; the run is claimed per agency per day.
// everyFifteenMinutes in kt:minute fifteen (MinuteBatchCommand) - one process for the group.
// Cold reach: replies, due follow-ups (bell the owner), automatic follow-up emails when switched on (2026-10-30).
// everyFifteenMinutes in kt:minute fifteen (MinuteBatchCommand) - one process for the group.
// New invoices and payslips become personal announcements for the people they belong to (2026-10-31).
// everyTwoMinutes in kt:minute two (MinuteBatchCommand) - one process for the group.
// Payslip emails to staff when a payslip arrives (App\Support\PayslipNotices; agency Payroll settings).
// everyTwoMinutes in kt:minute two (MinuteBatchCommand) - one process for the group.
// Counter-signature reminders: one digest per admin/director of completed forms still
// waiting for review, on each agency's schedule (Settings → Notifications). 2026-10-09.
// everyFifteenMinutes in kt:minute fifteen (MinuteBatchCommand) - one process for the group.
/* Providers without a bio: each agency's schedule (Settings → Notifications → Provider bios). */
// everyFifteenMinutes in kt:minute fifteen (MinuteBatchCommand) - one process for the group.
/* Drill reminders: only agencies that switched them on (Drill log screen); weekdays 9:00 agency time. */
// everyFifteenMinutes in kt:minute fifteen (MinuteBatchCommand) - one process for the group.
/* Headcount reminders: only agencies that switched them on (Headcounts screen); rooms with children
   signed in and no count within the agency's interval. Bell + push to clocked-in educators, never SMS. */
// everyFiveMinutes in kt:minute five (MinuteBatchCommand) - one process for the group.
/* Missed headcounts (2026-11-03): a reminder with no count of that room within 15 minutes becomes a strike
   (Daily Overview + the educator's daily summary) and the centre's directors are told. HeadcountStrikesCommand. */
// everyFiveMinutes in kt:minute five (MinuteBatchCommand) - one process for the group.
/* New families and withdrawn children ring the bell for admins, directors and the centre's educators, once each,
   however they were created or withdrawn (2026-11-03, App\Support\RosterAlerts). */
// everyFiveMinutes in kt:minute five (MinuteBatchCommand) - one process for the group.
/* Daily records reminders (2026-10-15): each agency after its own send time (Settings > Room
   Controls > Daily records), once per educator per day - App\Support\RecordReminders. */
// everyFifteenMinutes in kt:minute fifteen (MinuteBatchCommand) - one process for the group.
// Overdue sleep checks (2026-10-13, Ontario O. Reg. 137/15 s.33.1): one cheap query a minute instead of phones polling.
/* THE EVERY-MINUTE (AND EVERY 2/5/10/15-MINUTE) JOBS, ONE PROCESS PER GROUP (2026-11-10, the portal speed sweep). Each used to start its own
   PHP (twice when in the background): 22 start-ups a minute, more CPU than all web traffic, and pages 3-4x slower in the
   first seconds of every minute. MinuteBatchCommand::GROUPS lists the jobs; safety alerts have a group of their own. */
Schedule::command('kt:minute safety')->everyMinute()->withoutOverlapping(10)->runInBackground()->onOneServer();
Schedule::command('kt:minute work')->everyMinute()->withoutOverlapping(10)->runInBackground()->onOneServer();
Schedule::command('kt:minute two')->everyTwoMinutes()->withoutOverlapping(5)->runInBackground()->onOneServer();
/* OFF THE HOST'S FIVE-MINUTE MARK (2026-11-11, Anthony: "check audit log and figure out why a page loaded slowly was
   logged"). The server's security agent (Imunify360, run by Cloudways as root: check_recurrent.py runs every five
   minutes from /etc/cron.d/imunify360-firewall) takes most of one core for ~12 s at :00, :05, :10 ... of every hour. Every 5/10/15/
   30-minute and hourly job here started on those same seconds, so both cores sat at 95-100% and pages opened then took
   3-7 s: API calls in the first 20 s of a five-minute mark averaged 0.91 s (9.6% over 2 s) against 0.12-0.33 s the rest
   of the time. Nothing but the every-minute and every-2-minute jobs starts on a multiple of five now; each tick below has
   its own minute so they do not stack on one another either. A new periodic job: give it a minute that is not a
   multiple of five (->cron('7-59/15 * * * *'), ->hourlyAt(n)). */
Schedule::command('kt:minute five')->cron('2-59/5 * * * *')->withoutOverlapping(15)->runInBackground()->onOneServer();      // :02 :07 :12 ...
Schedule::command('kt:minute ten')->cron('4-59/10 * * * *')->withoutOverlapping(30)->runInBackground()->onOneServer();      // :04 :14 :24 ...
Schedule::command('kt:minute fifteen')->cron('6-59/15 * * * *')->withoutOverlapping(30)->runInBackground()->onOneServer();  // :06 :21 :36 :51
// Every minute in kt:minute safety (App\Console\Commands\MinuteBatchCommand) - one process for the group.
/* Ontario drill schedule (O. Reg. 137/15 s.68 / s.68.2, 2026-10-13): missing-child drill due within 14 days or
   overdue, monthly fire drill overdue — directors (admins on overdue), once per change. CA-ON agencies only. */
// everyFiveMinutes in kt:minute five (MinuteBatchCommand) - one process for the group.
// A parent's pickup alarm (the countdown on the parent Today screen, 2026-11-09).
// Every minute in kt:minute safety (App\Console\Commands\MinuteBatchCommand) - one process for the group.
Schedule::command('drills:cadence-remind')->dailyAt('08:00')->timezone('America/Toronto')->withoutOverlapping();

/* Clear out dead API tokens. Every sign-in mints one and nothing ever removed one -- 796
   had accumulated, 281 of them already expired: full-access credentials (`["*"]`) sitting
   in the table long after the browser that earned them was closed. Removing an EXPIRED
   token signs nobody out, because Sanctum already refuses it.
   Runs at a quiet hour, and never touches a token without an expiry -- that is what the
   iLearn integration holds. See App\Console\Commands\PruneTokens. */
Schedule::command('tokens:prune --days=30')
    ->dailyAt('03:15')->timezone('America/Toronto')->withoutOverlapping();

/* Browser push registrations that can no longer be reached: not a subscription, idle
   60 days, or beyond the 10 most recent per person. See PruneWebPushTokens. */
Schedule::command('push:prune-web --days=60 --keep=10')
    ->dailyAt('03:20')->timezone('America/Toronto')->withoutOverlapping();

/* The IP-location database behind the new-sign-in email (App\Support\GeoIp). DB-IP
   publishes monthly; the 3rd leaves room for a late release. */
Schedule::command('geoip:update')
    ->monthlyOn(3, '04:40')->timezone('America/Toronto')->withoutOverlapping();

/* Training assigned with a due date: a reminder 2 days before, and once when overdue.
   Morning, so it lands at the start of a working day. See TrainingRemind. */
Schedule::command('training:remind')
    ->dailyAt('08:30')->timezone('America/Toronto')->withoutOverlapping();

/* A SIGNED FORM THAT NEVER REACHED THE FAMILY'S RECORD.

   Filing happens at signature time and is best-effort on purpose - a filing problem must
   not fail the submission the parent just made. The price is silence: if the account did
   not exist yet, or the write failed, the form is complete in Forms Manager and missing
   from the family's Documents, and nobody notices until somebody looks for it.

   So the question is asked again every night. Normally a no-op and silent; anything it
   has to fix, or cannot, is logged. See App\Console\Commands\SyncSignedFormDocuments. */
Schedule::command('forms:sync-documents')
    ->dailyAt('03:35')->timezone('America/Toronto')->withoutOverlapping();

/* Chases every incident that is still open until somebody closes it. An incident
   report nobody actions is the failure this exists to prevent, and until now
   nothing in the platform noticed. Silent when there is nothing open. */
Schedule::command('incidents:remind')
    ->dailyAt('08:00')
    ->timezone('America/Toronto')
    ->withoutOverlapping();

/* DAILY SUMMARIES WHEN EACH ONE IS DUE (2026-10-29, App\Support\SummaryTiming). Were fixed
   18:30 / 19:00 Toronto times. Now every 10 minutes: a parent's goes an hour (agency setting)
   after the child's last check-out, an educator's an hour after their last clock-out, else at
   the agency's own fallback time. summary_sends makes each one go once a day at most. */
// everyTenMinutes in kt:minute ten (MinuteBatchCommand) - one process for the group.
// everyTenMinutes in kt:minute ten (MinuteBatchCommand) - one process for the group.

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
/* Ask Zum whether our record of every in-flight payment still matches theirs, and check
   the invoices against the payments ledger. Settlement is asynchronous, so a webhook that
   stops being delivered is invisible by design — on 2026-09-02 a transaction read Completed
   at Zum and 'submitted' here with nothing to notice it. Read-only: it reports, it does not
   settle. Run --fix by hand after looking at what it found. */
Schedule::command('payments:reconcile --days=45')
    ->dailyAt('05:15')->timezone('America/Toronto')->withoutOverlapping();

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

/* Keep each centre's rota filled a rolling four weeks ahead. Runs after care:sync-rooms so
   room placement is settled first -- an educator_rooms row written at 04:15 decides which
   room the 04:30 fill puts them in. Opt-in per agency (settings.schedule_autofill): an
   agency that builds its rota by hand must not find it built for them. It never touches a
   day that already has a shift, so a hand-edited or hand-deleted day survives the night. */
Schedule::command('schedule:autofill --days=28')
    ->dailyAt('04:30')->timezone('America/Toronto')->withoutOverlapping();

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
// everyFiveMinutes in kt:minute five (MinuteBatchCommand) - one process for the group.

/* Switched-off accounts lose their API tokens within 15 minutes, whichever path set
   the status. Deactivating used to block only NEW logins. */
// everyFifteenMinutes in kt:minute fifteen (MinuteBatchCommand) - one process for the group.

/* Missed-chat email notifications.

   The delay is per agency — agencies.settings.chat_email_delay_minutes, default 5 — so
   the TICK has to be at least as frequent as the shortest delay anyone can choose.
   At the old fifteen-minute tick a five-minute setting really meant "five to twenty".

   The 2026-08-20 pause is over: that flood was one email per recipient per CONVERSATION
   (a person in 39 threads got 39 emails from a single run). It now groups into ONE digest
   per person per run, keyed by email address so two accounts sharing an inbox merge
   instead of doubling. Volume is bounded by real messages, not by the tick: every message
   is stamped email_notified_at when it goes out, so nobody is told twice about the same
   one however often this runs. */
Schedule::command('kiddietrac:chat-emails')
    ->cron('3-59/5 * * * *')   // :03 :08 ... - off the host's five-minute mark (see kt:minute five)
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

/* Payment-schedule invoices become live on the first of their own month.

   PaymentPlanController raises one DRAFT invoice per instalment when the schedule is
   agreed, dated to the first of the month it falls due in. This flips each to 'sent'
   once that day arrives, so a schedule agreed in September does not drop six debts
   into a family's account today for months that have not started.

   NOT gated behind a config flag, unlike late fees. A late fee adds money nobody
   agreed to; these instalments were entered and reviewed line by line by an admin
   before saving, and issuing them on the agreed date is the thing they asked for.

   06:00 so the invoice exists before the morning's billing reminders read it. The
   condition is "the issue date has passed", not "is today", so a missed day catches
   up on the next run instead of skipping the month. */
Schedule::command('invoices:issue-scheduled')
    ->dailyAt('06:00')
    ->withoutOverlapping();
Schedule::command('expiry:warn')->dailyAt('08:00');
/* KEEP THE REASON (ticket #72).

   05:00 today this exited 1 and filed a high-priority ticket saying exactly that and
   nothing else — because a scheduled command's output goes to /dev/null unless it is
   asked for, so the one thing that would explain the failure was discarded as it
   happened. It has run clean every other day this week and runs clean by hand, which
   makes it exactly the kind of intermittent failure that is unfixable without its
   output.

   Appended rather than overwritten, and only for this command: a scheduler that logs
   every run of every command fills a disk, and this is the one that has failed. */
/* EVERY HOUR (2026-11-04): the command writes only what has already happened at each centre (SeedDemoDaily, "nothing
   from the future"), so the demo day fills in as it goes - one run at 05:00 would leave a day with nothing in it. */
Schedule::command('demo:seed-daily')->hourlyAt(14)->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/schedule-output.log'));

/* BRIGHTWOOD (2026-10-28): the white-label California demo agency (kt:seed-brightwood-demo) gets its own fresh day
   too. Looked up by slug, because --rebuild gives the agency a new id. Silent like the rest of the demo. */
Schedule::call(function () {
    $id = \Illuminate\Support\Facades\DB::table('agencies')->where('slug', \App\Console\Commands\SeedBrightwoodDemo::SLUG)->value('id');
    if ($id) \Illuminate\Support\Facades\Artisan::call('demo:seed-daily', ['--agency' => (int) $id]);
})->name('demo-seed-daily-brightwood')->hourlyAt(22)->withoutOverlapping();   // hourly: see demo:seed-daily above
/* STARLIGHT (2026-10-30): the white-label website demo (kt:seed-starlight-demo), the same fresh day, by slug. */
Schedule::call(function () {
    $id = \Illuminate\Support\Facades\DB::table('agencies')->where('slug', \App\Console\Commands\SeedStarlightDemo::SLUG)->value('id');
    if ($id) \Illuminate\Support\Facades\Artisan::call('demo:seed-daily', ['--agency' => (int) $id]);
})->name('demo-seed-daily-starlight')->hourlyAt(28)->withoutOverlapping();   // hourly: see demo:seed-daily above
/* ^ IN BRIGHTWOOD'S OWN MORNING (2026-10-31). At 05:10 UTC - 10:10 pm the evening before in California - each demo
   "day" was seeded after it was over, and its 7:30 am shift then stayed open all the next day ("Checked in 15h"). */
// Fictional demo shifts end after a working day (App\Support\PublicDemo::endShifts) instead of running all night.
Schedule::call(fn () => \App\Support\PublicDemo::endShifts())->name('public-demo-end-shifts')->cron('11-59/15 * * * *')->withoutOverlapping();


Schedule::command('drip:dispatch')->hourlyAt(1);
// The public website demo: remove what visitors added, revoke expired demo logins (2026-10-31).
Schedule::command('kt:reset-web-demo')->dailyAt('03:30')->timezone('America/Toronto')->withoutOverlapping();
/* THE WEBSITE DEMO STORY, FRESH EVERY NIGHT (2026-11-26, Anthony: yes). After the reset above, Brightwood (25) and
   Starlight (26) get their linked story rewritten relative to the new day (App\Support\WebDemoShowcase): its ledger
   removes exactly what the last run wrote first, so nothing piles up; it refuses any agency that is not a test agency.
   03:45 Toronto = 00:45 in the demo agencies' own California night. One short CLI run, not every-minute work. */
// KiddieTrac HR (2026-11-27): leave grants, accruals, carry-over and expiry. Ledger rows only, sends nothing.
Schedule::command('hr:leave-accrue')->dailyAt('02:20')->timezone('America/Toronto')->withoutOverlapping(30)->onOneServer();
// KiddieTrac HR phase 2 (2026-11-28): onboarding / course / review reminders (bell + phone), agency dates; test agencies skipped.
Schedule::command('hr:talent-remind')->dailyAt('07:40')->timezone('America/Toronto')->withoutOverlapping(30)->onOneServer();
// KiddieTrac HR (2026-11-28): courses assigned to a role reach people who join it later (the screens do it at once; this
// catches imports and provisioning). Quiet unless a rule exists. Not every minute: every 15.
Schedule::command('hr:role-courses')->everyFifteenMinutes()->withoutOverlapping(10)->onOneServer();
// KiddieTrac HR (2026-11-28): declined agency applicants deleted after the agency's N days (default 90, Never allowed).
// Deletes, sends nothing, so test agencies are included; platform careers keep kt:careers-retention below.
Schedule::command('hr:declined-purge')->dailyAt('03:50')->timezone('America/Toronto')->withoutOverlapping(20)->onOneServer();
Schedule::command('kt:web-demo-showcase --set=all')->dailyAt('03:45')->timezone('America/Toronto')->withoutOverlapping(30)->onOneServer()
    ->appendOutputTo(storage_path('logs/web-demo-showcase.log'));
Schedule::command('portfolio:year-end')->yearlyOn(12, 15, '09:00');

// Mail goes out half an hour before the in-app bells, so the email about a birthday
// arrives before the notification about it. Toronto explicitly: a birthday greeting
// timed by a UTC server lands in the middle of the night.
// Hourly, not once a night: the job only closes what is already past its agency's
// sign-off time, and running often means a forgotten punch is corrected while the day
// it belongs to is still recent enough for somebody to query it.
Schedule::command('kiddietrac:auto-signoff')->hourlyAt(2)->withoutOverlapping();

/* Delivery failures. Until this existed, email_logs said 'sent' whether the
   message arrived or was rejected outright -- bounces piled up unread in the
   server mailbox and nothing in the portal ever knew. */
Schedule::command('mail:ingest-bounces')->hourlyAt(3)->withoutOverlapping();
Schedule::command('kiddietrac:birthday-emails')->dailyAt('07:00')->timezone('America/Toronto');
Schedule::command('birthdays:celebrate')->dailyAt('07:30')->timezone('America/Toronto');   // morning bells; "today" is each agency's own day (2026-10-21)

// v22p98 — staff time-clock reminders (compliance/payroll/reporting)
// Hourly, not two fixed times: each agency sets its own reminder hours in Settings ->
// Clock settings, and the command skips any agency whose hour is not the current one in
// ITS timezone. A centre opening at 06:00 was previously nudged at 10:00 like everybody
// else. Weekend skipping is per agency now too, so the schedule no longer assumes it.
Schedule::command('staff:clock-reminders --mode=all --scheduled')->hourlyAt(4)->withoutOverlapping();
// Lunch / break running over its limit: remind the educator, then alert directors/admins (2026-11-06, BreakOverrunCommand).
// Every minute in kt:minute safety (App\Console\Commands\MinuteBatchCommand) - one process for the group.
// Still clocked in after every child has left the room (2026-11-07): an app reminder to clock out (Clock settings).
// everyFiveMinutes in kt:minute five (MinuteBatchCommand) - one process for the group.

// Weekly portal tips — a rotating "did you know?" push to parents + educators
// (APK notification + in-app bell). Wednesday mid-morning for good engagement.
Schedule::command('kiddietrac:portal-tips')->weeklyOn(3, '10:00')->withoutOverlapping();

// SOC 2 — security monitoring: scan the audit log for auth anomalies.
// everyFifteenMinutes in kt:minute fifteen (MinuteBatchCommand) - one process for the group.

// SOC 2 — Availability: nightly verified database backup (retains 14 days).
/* The hour is a PORTAL SETTING now (Settings -> Backups), not a constant. Read here
   rather than inside the command, because dailyAt() is what decides whether the command
   runs at all — a command that checks the clock itself would have to be scheduled every
   minute and then do nothing 1439 times a day.

   Wrapped: routes/console.php is evaluated on every schedule:run tick (once a minute), so
   a missing table or an unreadable value must fall back to the old fixed time rather than
   take the whole scheduler down with it. */
// Server health history for the super admin's page: one sample every 5 minutes, kept 14 days.
// everyFiveMinutes in kt:minute five (MinuteBatchCommand) - one process for the group.

Schedule::command('db:backup')
    ->dailyAt((function () {
        try {
            $t = (string) \App\Support\PlatformSettings::get('backup.time', '03:30');

            return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) ? $t : '03:30';
        } catch (\Throwable $e) {
            return '03:30';
        }
    })())
    ->withoutOverlapping();

// Invoice/payment reminders — hourly; gated by config('billing.reminders_enabled')
// (OFF) + per-agency settings; only fires in each agency's send-time hour.
Schedule::command('billing:reminders')->hourlyAt(6)->withoutOverlapping();
// Yesterday's payments to each agency's admins + directors, 7 AM agency time (App\Support\PaymentAlerts, 2026-11-05).
Schedule::command('billing:payment-summary')->hourlyAt(8)->withoutOverlapping();
// Overdue privacy breaches to admins + the breach centres' directors, 8 AM agency time (App\Support\PrivacyBreachAlerts, 2026-11-06).
Schedule::command('privacy:breach-reminders')->hourlyAt(9)->withoutOverlapping();
/* Hourly for the same reason billing reminders are: each agency picks its own
   weekday and local send time, so the command decides whether this is the hour.
   Every agency is opted out until they turn it on in Settings -> Email. */
Schedule::command('immunization:reminders')->hourlyAt(11)->withoutOverlapping();

// Async email/queue worker — drains queued jobs (invite emails, announcements,
// bulk sends) in the background so admin actions return instantly instead of
// blocking ~1.3–5.5s per email. --stop-when-empty exits when drained; --max-time
// keeps each run under a minute so the next tick restarts it.
/* THE OVERLAP LOCK MUST EXPIRE IN MINUTES, NOT A DAY (2026-09-14).

   withoutOverlapping() with no argument holds its lock for 1440 minutes. That is
   only ever released by schedule:finish at the end of a run — so a run that is
   KILLED rather than finished leaves the lock behind for twenty-four hours, and
   every tick in between skips silently. Nothing is logged, because nothing ran.

   That happened today. The worker ran every minute for months, the 12:50:03 run
   was killed (this is shared hosting and long-running PHP gets reaped), and the
   queue stopped draining. By the time anybody noticed, 33 emails were stranded —
   parents' sign-in notices, clock-in reminders, and an invite and a password
   reset for a member of staff who was waiting on them. The symptom was that mail
   simply never arrived, with no error anywhere: the audit log recorded the admin
   pressing the button, and no email_logs row was ever written, because the send
   had not yet been attempted.

   --max-time=55 means a legitimate run cannot exceed about a minute, so a lock
   held beyond a few minutes is always wreckage. Five minutes gives a slow run
   room and caps the damage from a killed one at five minutes instead of a day. */
/* A WORKER THAT DIES OF EXHAUSTION CHARGES THE JOB FOR IT (tickets #73, #74).

   2026-09-16 13:34: the worker hit PHP's 128 MB limit and was killed mid-send. The job
   it was holding — a check-in reminder to a parent at iLearn — was not at fault and was
   charged an attempt anyway. That happened three times, and at 13:36 the job was moved
   to failed_jobs, so the reminder was never sent and the only trace was two
   high-priority tickets about an exception nobody could tie to an email.

   Measured while chasing it: the mail pipeline does not leak. 60 consecutive sends
   through AgencyMailer, the suppression listener and the open pixel held flat at 32 MB,
   so there is nothing here to fix at the send site. What is missing is a ceiling of the
   worker's OWN, below PHP's, so it finishes the job in hand and retires rather than
   being killed in the middle of one. --stop-when-empty already means a restart costs
   nothing: the next tick is a minute away and picks up whatever is left.

   100, not 128: the check is made BETWEEN jobs, so the headroom has to cover the
   largest single job, and a send peaks at ~32 MB from a ~28 MB floor. */
/* "pictures" LAST (2026-11-24): Play Spark end-result drawings take ~30 s each (App\Jobs\DrawPlaySparkPicture), so they
   run only when no email or other job is waiting. */
Schedule::command("queue:work --queue=mail,default,pictures --stop-when-empty --max-time=55 --memory=100 --tries=3 --sleep=2 --backoff=30")
    ->everyMinute()->withoutOverlapping(5)->runInBackground();


// Daily sales-lead follow-up reminders (email owner/superadmin about due/overdue follow-ups).
/* Password expiry warnings: 14 days out, 7, 1, and once on the day it lapses. Each step
   is sent ONCE (users.password_expiry_notified_at holds which one), so a nightly sweep
   does not become a nightly nag - being pestered daily about a password is how people
   learn to ignore email from us.

   09:00 rather than overnight: a warning read at the start of a working day gets acted
   on; one that arrives at 3am is at the bottom of the inbox by breakfast. */
Schedule::command('kiddietrac:password-expiry')->dailyAt('09:00');

Schedule::command('kiddietrac:sales-followups')->dailyAt('08:00');
// Task reminders: app / email / text / voice, at the time set on the task (2026-10-27, App\Support\TaskReminders).
// everyFiveMinutes in kt:minute five (MinuteBatchCommand) - one process for the group.
// Agency contacts onto the KiddieTrac subscriber list, within the hour of onboarding (2026-10-27, App\Support\AgencySubscribers).
Schedule::command('kiddietrac:agency-subscribers')->hourlyAt(12)->withoutOverlapping(30);
// Sales campaigns: the next batch from sales@, bookings + replies from people a campaign reached (2026-10-26).
// Every minute in kt:minute work (App\Console\Commands\MinuteBatchCommand) - one process for the group.
// Email client (2026-11-02): new mail in a connected mailbox -> the bell (fresh rows only), and a daily use of each
// Microsoft refresh token so a mailbox never hits the 90-day idle sign-out. App\Console\Commands\MailWatchCommand.
// Every minute in kt:minute work (App\Console\Commands\MinuteBatchCommand) - one process for the group.
Schedule::command('kiddietrac:mail-watch --keepalive')->dailyAt('10:20')->withoutOverlapping(30);

// Nightly data-retention purge — enforces each agency's compliance policy
// (chat + announcements past their retention window). Opt-in per agency via
// Settings → Data Retention (auto_enforce). Off-hours so it never contends.
Schedule::command('retention:purge')->dailyAt('02:30')->withoutOverlapping();

// Scheduled reports — email due canned-report schedules hourly (fires once/day at/after each schedule send-time hour).
Schedule::command("kiddietrac:send-scheduled-reports")->hourlyAt(13)->withoutOverlapping();

// Any sender that sets X-KT-Logged without writing its own email_logs row leaves an
// email delivered but invisible in the log. The email.sent audit rows are the
// independent witness, so this reconciles the two nightly and backfills the gap,
// marking such rows "reconstructed from audit trail" so the omission stays visible.
Schedule::command("mail:reconcile-logs --days=3 --backfill")->dailyAt("06:15")->timezone("America/Toronto");

// Invited-but-not-onboarded nudges. The command self-gates to each agency's configured
// hour (default 07:00 ET) and caps itself at 4 reminders, >= 3 days apart, per invitee,
// so hourly dispatch is the intended cadence rather than hourly mail. This was never
// registered anywhere - in practice NOBODY was being reminded.
Schedule::command("kiddietrac:onboarding-reminders")->hourlyAt(16);


// The director / admin digest: what needs a decision today, and a wider view on Monday.
// 07:00 and 07:10 Toronto — before the day starts, and far enough apart that a slow
// weekly run cannot delay the daily one.
Schedule::command('kiddietrac:admin-digest')->dailyAt('07:00')->timezone('America/Toronto')->withoutOverlapping();
/* RESCUED FROM app/Console/Kernel.php (2026-10-30). That file is not read by Laravel 11, so these never ran and
   Platform → AI digest status showed every child "pending" every day. The AI digest is built for the status page
   only - no parent push (parents get the daily summary after check-out). Mail health warns superadmins before the
   Microsoft mail secret expires. */
Schedule::command('kiddietrac:generate-digests --no-notify')->dailyAt('18:00')->timezone('America/Toronto')->withoutOverlapping(60);
Schedule::command('kiddietrac:mail-health')->dailyAt('07:30')->timezone('America/Toronto')->withoutOverlapping();
Schedule::command('kiddietrac:admin-digest --weekly')->weeklyOn(1, '07:10')->timezone('America/Toronto')->withoutOverlapping();
// Superadmin weekly summary: texts, calls and AI spend per agency, over-threshold first (2026-10-20).
Schedule::command('platform:usage-summary')->weeklyOn(1, '07:15')->timezone('America/Toronto')->withoutOverlapping();

/* Nightly billing. Installed but INERT: the command returns immediately unless
   billing.auto_raise is true in platform_settings, and refuses to email while
   invoice.issuer.address / tax_id are unset. Scheduling it costs nothing until
   those are deliberately turned on. */
/* RECURRING INVOICES run at the time set in Accounting AR/AP -> Billing plans (Toronto time, default 06:00). Read
   each time the scheduler starts; a bad value falls back to 06:00. Licence renewals (kt:licences) raise their
   invoices in the same run window, 10 minutes earlier, so both land together (2026-10-25). */
$ktRunAt = rescue(function () {
    $t = (string) (json_decode((string) \Illuminate\Support\Facades\DB::table('platform_settings')->where('key', 'billing.recurring')->value('value'), true)['run_time'] ?? '06:00');
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) ? $t : '06:00';
}, '06:00', false);
$ktLicAt = \Carbon\Carbon::createFromFormat('H:i', $ktRunAt)->subMinutes(10)->format('H:i');
Schedule::command('platform:billing-run')->dailyAt($ktRunAt)->timezone('America/Toronto')->withoutOverlapping();
// Due-soon / due-today / overdue emails for KiddieTrac invoices (2026-10-25; settings in Sales → Invoices).
// Form mail withheld from a parent = a bug; report it (2026-10-25).
// Every 15 minutes, resending anything still withheld (2026-10-25: daily was too slow for Sheldon's countersigned copies).
// everyFifteenMinutes in kt:minute fifteen (MinuteBatchCommand) - one process for the group.
// A signed form's completed copy that never reached the form's address: send it and say so (2026-10-25).
Schedule::command('kt:form-copy-watch --fix')->cron('17,47 * * * *')->withoutOverlapping();
Schedule::command('platform:invoice-notices')->dailyAt('09:00')->timezone('America/Toronto')->withoutOverlapping();

/* Scheduled announcements (2026-09-29): nothing sent them before this. Every minute;
   each row is claimed before delivery so an overlapping run cannot send it twice. */
// Every minute in kt:minute work (App\Console\Commands\MinuteBatchCommand) - one process for the group.

/* Safe Arrival (2026-09-29): ask parents about children who have not arrived, then alert
   staff. Only agencies that switched it on; see App\Support\SafeArrival. */
// Visible reference IDs for anything created since the last minute (App\Support\RefCode).
// Every minute in kt:minute work (App\Console\Commands\MinuteBatchCommand) - one process for the group.
// Every minute (2026-10-23) so the arrival/departure thresholds start on the minute, not up to 5 late.
// Every minute in kt:minute safety (App\Console\Commands\MinuteBatchCommand) - one process for the group.
/* The next guardian's Safe Arrival call, once the agency's wait after the last unanswered call
   is up (SafeArrivalCall gap_secs, default 1 minute). Cheap: today's open checks with a call. */
/* Transcribe new voicemails and recorded calls and file them on the caller's lead (2026-10-23). */
/* Automatic proposal follow-ups that are due (weekdays 9-5 ET), 2026-10-23. */
/* Leads onto the subscriber list - the ones written without the model (2026-10-23). */
Schedule::command('kt:leads-subscribe')->hourlyAt(17)->withoutOverlapping(10)->runInBackground()->onOneServer();
// Supplies from home (2026-10-23): a request still open after its day tells whoever asked, once.
Schedule::command('kt:supply-overdue')->hourlyAt(23)->withoutOverlapping(10)->runInBackground()->onOneServer();
// Licences (2026-10-24): renewals (draft invoices when billing is on), grace, expiry, reminders.
Schedule::command('kt:licences')->dailyAt($ktLicAt)->timezone('America/Toronto')->withoutOverlapping(30)->runInBackground()->onOneServer();
// KiddieTrac's recurring expenses, added on their dates (Sales -> Expenses, 2026-10-24).
Schedule::command('kt:expenses-recurring')->dailyAt('05:50')->withoutOverlapping(30)->runInBackground()->onOneServer();
Schedule::command('kt:proposal-followups')->hourlyAt(18)->withoutOverlapping(30)->runInBackground()->onOneServer();
Schedule::command('kt:call-transcripts')->everyTwoMinutes()->withoutOverlapping(10)->runInBackground()->onOneServer();
// Every minute in kt:minute safety (App\Console\Commands\MinuteBatchCommand) - one process for the group.

/* Weekly What's new email (2026-09-29): Mondays 9am, only when there is something new,
   each person only what applies to their role. See WhatsNewDigestCommand. */
Schedule::command('kiddietrac:whats-new-digest')->weeklyOn(1, '09:00')->timezone('America/Toronto')->withoutOverlapping(60)->onOneServer();

/* GUARDS: nothing closed in anything current (2026-10-09). Closed providers got
   Thanksgiving notices and a withdrawn child was on an immunization reminder; see
   KtIntegrityCheck (records closed in one table, live in another) and KtLeakSweep
   (every current endpoint + the last day's sent mail). Both email the platform admins
   when they find anything, and run before the morning's reminders and summaries. */
Schedule::command('kt:integrity-check')->dailyAt('05:30')->timezone('America/Toronto')->withoutOverlapping(30)->onOneServer();
Schedule::command('kt:leak-sweep')->dailyAt('05:40')->timezone('America/Toronto')->withoutOverlapping(60)->onOneServer();
/* Global search coverage (2026-11-02): fails (and emails the platform admins) when a main record
   type or a newly created table has no search source and no written reason. See
   App\Support\SearchRegistry and KtSearchCoverage. */
Schedule::command('kt:search-coverage')->dailyAt('05:45')->timezone('America/Toronto')->withoutOverlapping(15)->onOneServer();
/* Plan + add-on wiring (2026-11-26): fails (a ticket, like the other checks) when a new API route group, portal screen
   or scheduled job is not tied to a feature or recorded as core, when a job mapped to a feature stops asking
   App\Support\Features, or when an add-on product switches on a key that is no feature. See AddonCoverageCommand. */
Schedule::command('kt:addon-coverage --quiet-ok')->dailyAt('05:55')->timezone('America/Toronto')->withoutOverlapping(15)->onOneServer();
/* The global search words learned from use: retire stale ones, mail only when learning stops (SearchKeysCommand). */
Schedule::command('search-keys:health')->dailyAt('05:50')->timezone('America/Toronto')->withoutOverlapping(15)->onOneServer();
// Photo permissions (2026-11-06): hide photos of a child whose family said no; fail on code reading photos without the rule.
Schedule::command('kt:photo-consent-check')->dailyAt('05:50')->timezone('America/Toronto')->withoutOverlapping(15)->onOneServer();
// Counter-signatures (2026-11-06): the worker stamps them; this re-runs any it did not finish, and after an hour sends the original and alerts.
// everyFifteenMinutes in kt:minute fifteen (MinuteBatchCommand) - one process for the group.
// Signature screens (2026-11-06): fail on any that draws its own signature canvas instead of the shared pad (saved signatures).
Schedule::command('kt:signature-pad-check --quiet-ok')->dailyAt('05:55')->timezone('America/Toronto')->withoutOverlapping(15)->onOneServer();
// Every notification kind says who it is for; no staff-only alert reaches a parent (2026-11-06, NotifyAudience).
Schedule::command('kt:notify-audience-check --quiet-ok')->dailyAt('05:50')->timezone('America/Toronto')->withoutOverlapping(15)->onOneServer();
// Every staff child list holds the children that person looks after; enrolment counts agree (2026-11-09).
Schedule::command('kt:child-scope-check --quiet-ok')->dailyAt('05:55')->timezone('America/Toronto')->withoutOverlapping(20)->onOneServer();
// Each role sees only its own screens: admin routes guarded, deny list, one tile badge (2026-11-06 role sweep).
Schedule::command('kt:screen-guard-check --quiet-ok')->dailyAt('05:45')->timezone('America/Toronto')->withoutOverlapping(15)->onOneServer();
/* Raw escape codes (\u{...}) in code strings and in the last day's email (2026-10-09:
   "the year\u{2019}s" reached 59 inboxes). MailText repairs mail at send time; this
   finds the source. See KtTextCheck. */
// Missed calls on the KiddieTrac line: app / email / text alerts (App\Support\CallAlerts, 2026-10-23).
// Prospects who have not sent the intake form: one reminder a day until the meeting (2026-10-23).
Schedule::command('kt:intake-reminders')->dailyAt('09:00')->timezone('America/Toronto')->withoutOverlapping(30)->onOneServer();
// Every minute in kt:minute safety (App\Console\Commands\MinuteBatchCommand) - one process for the group.
// Every section banner shimmers: gradient range, animation not switched off, white-label size kept (2026-11-24).
Schedule::command('kt:banner-shimmer-check --quiet-ok')->dailyAt('05:52')->timezone('America/Toronto')->withoutOverlapping(10)->onOneServer();
Schedule::command('kt:text-check')->dailyAt('05:50')->timezone('America/Toronto')->withoutOverlapping(30)->onOneServer();
// Job applications kept on file: removed when their 24 months are up, as the applicant was told (2026-11-24).
Schedule::command('kt:careers-retention')->dailyAt('03:40')->timezone('America/Toronto')->withoutOverlapping(10)->onOneServer();
/* Xero: agencies that turned on automatic sync in Settings → Xero (off by default). */
Schedule::command('xero:sync')->cron('9,39 * * * *')->withoutOverlapping(25)->runInBackground()->onOneServer();
/* QuickBooks: the same, for agencies with automatic sync on in Settings → QuickBooks. */
Schedule::command('qbo:sync')->cron('19,49 * * * *')->withoutOverlapping(25)->runInBackground()->onOneServer();
/* Sage Accounting (cloud): the same, for agencies with automatic sync on in Settings → Sage. */
Schedule::command('sage:sync')->cron('29,59 * * * *')->withoutOverlapping(25)->runInBackground()->onOneServer();
/* Ohio PFCC (2026-10-10): unpaid copays and the absent-day cap — bell only, to directors and
   admins, only for agencies where PFCC is on. See PfccAlertsCommand. */
Schedule::command('pfcc:alerts')->dailyAt('07:15')->timezone('America/New_York')->withoutOverlapping(30)->onOneServer();

// Policy library (2026-10-13): weekly — Mondays 8:00 agency time, once per ISO week — staff with policies to sign,
// and director/admin summaries with reviews due within 30 days (O. Reg. 137/15 s.6.1, s.46). On unless switched off.
Schedule::command('policies:remind')->hourlyAt(19)->withoutOverlapping(30)->onOneServer();
// OCCMS Record of Attendance (2026-10-13): hourly; sends at 8:00 agency time on the 1st (ready to review) and two days
// before the due day (not signed off). Ontario agencies with fee-subsidized children only; once per month and kind.
Schedule::command('occms:roa-remind')->hourlyAt(7)->withoutOverlapping(30)->onOneServer();

// Unused temporary (forms-only) parent accounts past each agency's limit — App\Support\TempGuardians (2026-10-13).
Schedule::command('kt:temp-guardians-expire')->dailyAt('03:45')->timezone('America/Toronto')->withoutOverlapping();

// Report cards each quarter (2026-11-04): in the last 14 days of a school-year quarter, one bell + push per educator
// listing the children in their rooms with no card for it yet. Once per educator per quarter (report_card_reminders).
Schedule::command('kiddietrac:report-card-reminders')->dailyAt('09:10')->timezone('America/Toronto')->withoutOverlapping(30)->onOneServer();

// Field-level encryption safety net (App\Support\Sensitive, 2026-10-21): the database connection
// seals children's health fields on every write; this seals anything a write path left in
// plaintext. Idempotent and cheap (only rows not yet sealed are read).
// everyTenMinutes in kt:minute ten (MinuteBatchCommand) - one process for the group.

/* Forgotten walks (2026-10-21, Anthony: "close them quietly" + a safeguard). A walk still
   open after its own agency day is closed at its last GPS point - no parent or staff
   notifications; one bell to the educator who started it. Hourly so each agency's
   midnight is caught soon after it passes (App\Support\WalkCleanup). */
Schedule::command('walks:close-forgotten')->hourlyAt(31)->withoutOverlapping();

/* Walks gone quiet (2026-10-28, Anthony: "make sure educator is prompted if walk is over"). A live walk with no
   location for 20 minutes - the phone lost signal, the app was closed, or everyone is back and nobody ended it -
   gets one bell + push to the educator who started it, at most once an hour. Nothing is closed and parents are
   not told: the educator decides. */
// everyFiveMinutes in kt:minute five (MinuteBatchCommand) - one process for the group.

// Ratio breach alerts (2026-10-30): a room out of ratio tells its educators / directors (per agency settings) once per
// episode, and again when it is back in ratio. It lived only in the dead app/Console/Kernel.php and the command did not exist.
Schedule::command('kiddietrac:check-ratios')->cron('1-59/15 * * * *')->between('06:00', '19:00')->timezone('America/Toronto')->withoutOverlapping(14);

// Automatic monthly thank-you awards (2026-10-31, App\Support\ThankYouAuto): each agency with it on, once its chosen day
// and time (agency time zone) has passed, for the previous month, once. Also expires drafts nobody sent.
// everyFifteenMinutes in kt:minute fifteen (MinuteBatchCommand) - one process for the group.

/* SUPPORT SLA ALERTS (2026-11-01, Anthony: "when SLA's are breached with support tickets are emails not sent out"). A ticket
   past its first-response or resolve target alerts superadmins (and its agency's admins) once, then daily. */
// everyFifteenMinutes in kt:minute fifteen (MinuteBatchCommand) - one process for the group.

/* SALES MEETINGS (2026-11-04, Anthony): meetings on the sales@ calendar onto their leads, and a 30-minute email + text
   reminder to the lead (App\Support\SalesMeetings; reminders only while PlatformSettings sales.meeting_reminders = 1). */
// everyFiveMinutes in kt:minute five (MinuteBatchCommand) - one process for the group.

/* SCHEDULER.LOG STAYS SMALL (2026-11-10, PIPEDA review housekeeping). The cron line appends every minute's
   "Running [...] DONE" to storage/logs/scheduler.log and nothing ever cut it: 87 MB / 705k lines. No personal data in
   it (checked: no addresses), just bulk. Nightly: past 20 MB, keep the newest ~10 MB from a line boundary. A line
   appended while it is being cut can be lost; nothing else is. */
Schedule::call(function () {
    $f = storage_path('logs/scheduler.log');
    clearstatcache(true, $f);
    if (! is_file($f) || filesize($f) < 20 * 1024 * 1024) return;
    // In place (r+), so the file keeps its owner and mode: cron writes it as another user.
    $h = fopen($f, 'r+b');
    if (! $h || ! flock($h, LOCK_EX)) return;
    fseek($h, -10 * 1024 * 1024, SEEK_END);
    fgets($h);                                    // drop the partial first line
    $keep = stream_get_contents($h);
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, $keep);
    flock($h, LOCK_UN);
    fclose($h);
})->name('scheduler-log-trim')->dailyAt('03:20')->timezone('America/Toronto')->onOneServer();
