<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Support\AgencyTime;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * v22p98 — Staff clock-in/out reminders.
 *
 * Educators/directors record their hours via the time clock (time_punches).
 * Accurate punches matter for THREE reasons we spell out in the email:
 *   • Compliance — licensing requires verifiable educator:child ratio records.
 *   • Payroll    — hours worked drive pay; a missing punch means a missed shift.
 *   • Reporting  — attendance/CACFP/funding reports reconcile against staff hours.
 *
 * Two modes (scheduled separately, weekdays):
 *   --mode=clock_in   (morning)  → staff who USUALLY work today but have no punch yet.
 *   --mode=clock_out  (evening)  → staff still clocked in (open entry) — forgot to clock out.
 */
final class ClockReminderCommand extends Command
{
    protected $signature = 'staff:clock-reminders
        {--mode=all : clock_in | clock_out | all}
        {--scheduled : only remind agencies whose configured hour is now (for the hourly cron)}
        {--dry-run : list who would be reminded without sending}';
    protected $description = 'Email educators/directors who forgot to clock in or out (compliance, payroll, reporting).';

    private bool $dryRun = false;


    /** @var bool Only remind agencies whose configured hour is the current one. */
    private bool $scheduled = false;

    /** @var array<int,int|null> centre id => agency id */
    private array $agencyOfCentre = [];

    /** @var array<int,array> agency id => clock reminder settings */
    private array $cfgOfAgency = [];

    /**
     * Should this agency be reminded right now, for this kind of reminder?
     *
     * Run by hand (no --scheduled) the answer is yes as long as the reminder is
     * switched on, so a test send does not depend on the hour. On the cron it also
     * has to be the agency's configured HOUR, in the agency's own timezone.
     *
     * @param  string  $which  in|out
     */
    private function agencyWants(?int $centreId, string $which): bool
    {
        if (! $centreId) {
            return false;
        }

        if (! array_key_exists($centreId, $this->agencyOfCentre)) {
            $this->agencyOfCentre[$centreId] = DB::table('centres')->where('id', $centreId)->value('agency_id');
        }
        $agencyId = $this->agencyOfCentre[$centreId];
        if (! $agencyId) {
            return false;
        }

        if (! isset($this->cfgOfAgency[$agencyId])) {
            $this->cfgOfAgency[$agencyId] =
                \App\Http\Controllers\Api\ClockRemindersController::read((int) $agencyId);
        }
        $cfg = $this->cfgOfAgency[$agencyId];

        if (empty($cfg[$which === 'in' ? 'in_enabled' : 'out_enabled'])) {
            return false;
        }
        if (! $this->scheduled) {
            return true;
        }

        $tz = \App\Support\AgencyTime::tz((int) $agencyId) ?: config('app.timezone');
        $now = Carbon::now($tz);
        if (! empty($cfg['weekdays_only']) && $now->isWeekend()) {
            return false;
        }

        $at = (string) ($cfg[$which === 'in' ? 'in_at' : 'out_at'] ?? '');
        $hour = (int) substr($at, 0, 2);

        return $now->hour === $hour;
    }
    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $this->scheduled = (bool) $this->option('scheduled');
        $mode = (string) $this->option('mode');
        $today = Carbon::today();
        $sent = 0;

        if ($mode === 'clock_out' || $mode === 'all') {
            $sent += $this->remindMissingClockOut($today);
        }
        if ($mode === 'clock_in' || $mode === 'all') {
            $sent += $this->remindMissingClockIn($today);
        }

        $this->info("Clock reminders sent: {$sent}");
        return self::SUCCESS;
    }

    /** Open time entry from today → they forgot to clock out. */
    private function remindMissingClockOut(Carbon $today): int
    {
        $open = DB::table('time_punches as t')
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->whereNull('t.punched_out_at')
            // Not whereDate(today): a punch left open overnight would never be
            // chased again, because the single evening that might have caught it has
            // gone. That is how one reaches 30 days. Fourteen days back is enough to
            // catch a forgotten shift while staying bounded.
            ->where('t.punched_in_at', '>=', $today->copy()->subDays(14))
            // Cheap floor; the real test is against each person's own usual day below.
            ->where('t.punched_in_at', '<=', Carbon::now()->subHours(4))
            ->whereNotNull('u.email')->whereNull('u.deleted_at')
            ->select('t.user_id', 't.centre_id', 't.punched_in_at', 'u.email', 'u.first_name')
            ->get();

        $n = 0;
        foreach ($open as $e) {
            if (! $this->agencyWants($e->centre_id ? (int) $e->centre_id : null, 'out')) {
                continue;
            }
            // Approved time off can start mid-day (leaving sick at noon), so an open
            // punch on such a day is expected, not a lapse.
            if ($this->isOnApprovedTimeOff((int) $e->user_id, $today)) continue;

            // Nudge once they are past their OWN usual day, not a flat six hours: an
            // afternoon shift that started at 12:30 is not overdue at 18:30, and
            // someone whose day normally ends at 15:00 should hear from us sooner.
            // punched_in_at is stored UTC. Formatting it raw printed a 9:17 AM
            // Eastern start as 1:17 PM, and decided "today" on a UTC boundary so an
            // evening shift was reported as an older one. Everything below is in the
            // AGENCY's timezone, resolved from the centre the punch belongs to.
            $tz = AgencyTime::tzForCentre($e->centre_id !== null ? (int) $e->centre_id : null);
            $in = Carbon::parse($e->punched_in_at)->setTimezone($tz);
            $nowLocal = Carbon::now($tz);
            $todayLocal = Carbon::today($tz);

            /* IF THEY ARE ROSTERED, THE ROSTER SAYS WHEN THE DAY ENDS.

               A median of the last month's punches cannot know that today is a closing
               shift. Somebody scheduled until 6pm was being chased from about 4pm on the
               strength of their usual 7.5 hours — a reminder to clock out of a shift
               they are still working, which is exactly the noise that teaches people to
               ignore these. While the scheduled end has not passed, there is nothing to
               chase. (2026-09-17) */
            $todayShift = $this->scheduledShift((int) $e->user_id, $e->centre_id ? (int) $e->centre_id : null, $in->toDateString());
            if ($todayShift && $todayShift->ends_at
                && $nowLocal->lt(Carbon::parse($todayShift->ends_at, $tz))) {
                continue;
            }

            $usual = $this->usualShiftHours((int) $e->user_id, $today);
            $threshold = $usual === null ? 6.0 : max(5.0, $usual + 1.0);
            // Elapsed time is the same number in any zone; only the labels moved.
            if ($in->floatDiffInHours($nowLocal) < $threshold) continue;

            $shiftWindow = $this->shiftWindow($todayShift);
            $inAt = $in->format('g:i A');

            if ($in->isSameDay($todayLocal)) {
                $howLong = number_format($in->floatDiffInHours($nowLocal), 1);
                /* Prefer the ROSTERED day to the inferred one. "Longer than your usual
                   day of about 7.5 hours" invites an argument; "your shift was scheduled
                   to end at 6:00 PM" is a fact somebody can check and correct. */
                $usualLine = $shiftWindow
                    ? ' Your shift today was scheduled ' . $shiftWindow . '.'
                    : ($usual === null
                        ? ''
                        : ' That is longer than your usual day of about ' . number_format($usual, 1) . ' hours.');
                $subject = 'Reminder: you\'re still clocked in';
                $bodyText = "You clocked in at {$inAt} today and have been on the clock for {$howLong} hours without clocking out.{$usualLine} Please clock out so your hours are recorded correctly. If you have already left, ask your administrator to correct the time — clocking out now would record the hours since you clocked in.";
            } else {
                // An older shift. Quoting the elapsed hours here would produce
                // "on the clock for 732.0 hours", which reads as a broken system
                // rather than a request. Give the date and ask for the out time.
                $days = (int) $in->copy()->startOfDay()->diffInDays($todayLocal->copy()->startOfDay());
                $when = $in->format('l j F') . ' at ' . $inAt;
                $subject = 'Your shift on ' . $in->format('j F') . ' was never clocked out';
                $bodyText = "You clocked in on {$when} and no clock-out was recorded, so that day's hours are still incomplete "
                    . ($days === 1 ? 'from yesterday' : "after {$days} days")
                    . ". Clocking out now would record every hour since then, so please ask your administrator to set the correct out time instead.";
            }

            $this->sendReminder(
                (int) $e->user_id, (int) $e->centre_id, $e->email, $e->first_name,
                $subject, $bodyText, $shiftWindow,
            );
            $n++;
        }
        return $n;
    }

    /**
     * No punch today for staff who USUALLY work this weekday (clocked in on the
     * same weekday in the prior 2 weeks). The same-weekday heuristic keeps us from
     * pestering staff on their regular days off (we have no shift schedule table).
     */
    private function remindMissingClockIn(Carbon $today): int
    {
        /* NO $dow HERE ANY MORE, and no weekend early-return. Both were derived from
           $today, which is Carbon::today() -- the APP timezone, UTC. From 8pm Toronto
           that names tomorrow: it made this method return 0 all Friday evening, run on
           Sunday evening as though it were Monday, and ask every guard below about the
           wrong day. Centres also span timezones. The date is resolved per centre inside
           the loop instead. (Anthony, 2026-09-07) */

        $staff = DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->whereIn('ra.role', ['educator', 'centre_director'])
            ->where('ra.active', true)->whereNotNull('ra.centre_id')
            ->whereNotNull('u.email')->whereNull('u.deleted_at')
            ->select('ra.user_id', 'ra.centre_id', 'u.email', 'u.first_name')
            ->distinct()
            ->get();

        $n = 0;
        foreach ($staff as $s) {
            if (! $this->agencyWants($s->centre_id ? (int) $s->centre_id : null, 'in')) {
                continue;
            }
            $centreId = $s->centre_id ? (int) $s->centre_id : null;

            /* THE DAY, IN THIS CENTRE'S OWN ZONE. Every decision below hangs off this.
               Closures::todayFor() is the same helper the closure rule uses, so the
               reminder and the closure cannot disagree about which day it is. */
            $cDate = \App\Support\Closures::todayFor($centreId);
            $cDow  = (int) Carbon::parse($cDate)->dayOfWeekIso; // 1=Mon..7=Sun
            if ($cDow >= 6) continue;      // their weekend, in their zone

            /* Agency-day instants. On the UTC date an evening clock-in did not count as
               "today", so this told somebody who HAD clocked in that they had not. */
            [$remFrom, $remTo] = \App\Support\AgencyTime::dayRangeForCentre($centreId, $cDate);
            $clockedToday = DB::table('time_punches')
                ->where('user_id', $s->user_id)
                ->where('punched_in_at', '>=', $remFrom)->where('punched_in_at', '<', $remTo)
                ->exists();
            if ($clockedToday) continue;

            // Approved vacation, sick or personal leave — they are not missing, they
            // are off, and the office already said so.
            if ($this->isOnApprovedTimeOff((int) $s->user_id, Carbon::parse($cDate))) continue;

            /* Centre shut today — a stat holiday, a snow day, or simply not one of its
               open days. Same class of fact as the leave check above: it says this
               person is not expected in. Nobody books Labour Day off in the leave
               table and Monday is a working weekday, so without this every
               Monday-regular educator at a closed centre was told to clock in on a
               holiday. isOperatingDay() is the helper the parent and educator
               summaries use, so all three agree on what a working day is.
               (Anthony, 2026-09-07) */
            /* BOTH QUESTIONS. isOperatingDay() only says whether the centre runs on
               this WEEKDAY -- it never reads centre_closures, so a stat holiday on a
               Monday looks like an ordinary working day to it. isClosed() is the one
               that sees the holiday. The parent summary has always tracked these as two
               separate fields (is_open_day / is_closed) for exactly this reason. */
            if (! \App\Support\Closures::isOperatingDay($centreId, $cDate)
                || \App\Support\Closures::isClosed($centreId, $cDate)) {
                continue;
            }

            /* THE ROSTER FIRST, HABIT ONLY AS A FALLBACK.

               Somebody who is on the staff calendar is expected when the calendar says
               so — not when a fortnight of punches suggests. Two silences come out of
               this, and both are reminders that should never have been sent:

                 • on the roster, nothing today  → they are not due in. Say nothing.
                 • on the roster, due at 12:30   → at 9am they are not late yet.

               Only somebody with no roster at all falls back to the weekday pattern,
               so an agency that has not opened the staff calendar is unaffected. */
            $tz = AgencyTime::tzForCentre($centreId);
            $shift = $this->scheduledShift((int) $s->user_id, $centreId, $cDate);
            $rostered = $this->hasRoster((int) $s->user_id, $cDate);

            if ($rostered) {
                if (! $shift) continue;                        // not on today's roster
                if (Carbon::now($tz)->lt(Carbon::parse($shift->starts_at, $tz))) {
                    continue;                                  // their shift has not started
                }
            } else {
                // Did they work this same weekday in the last 14 days? Their own history
                // is the best available statement of when they are expected in when
                // nobody has rostered them.
                $worksThisWeekday = DB::table('time_punches')
                    ->where('user_id', $s->user_id)
                    ->where('punched_in_at', '>=', Carbon::parse($cDate)->subDays(14))
                    ->whereRaw('WEEKDAY(punched_in_at) = ?', [$cDow - 1]) // MySQL WEEKDAY: 0=Mon
                    ->exists();
                if (! $worksThisWeekday) continue;
            }

            $window = $this->shiftWindow($shift);
            $lead = $window
                ? "You're scheduled today from {$window}, and no clock-in has been recorded yet. "
                  . 'If you are working, please clock in now so your hours are captured.'
                : "We noticed you're not clocked in today. If you're working, please clock in now "
                  . 'so your hours are captured. If you\'re off today, you can ignore this.';

            $this->sendReminder(
                (int) $s->user_id, (int) $s->centre_id, $s->email, $s->first_name,
                'Reminder: don\'t forget to clock in',
                $lead,
                $window,
            );
            $n++;
        }
        return $n;
    }

    /**
     * Is this person on APPROVED time off today? Covers vacation, sick and personal
     * leave - whatever the office has already approved, we do not second-guess with
     * a reminder. Pending requests deliberately do NOT suppress: nothing has been
     * agreed yet, and staying silent would hide a genuinely missing punch.
     *
     * Read live from time_off_requests so approving, amending or revoking a request
     * changes the reminders the same day, with no duplicated state to fall behind.
     */
    private function isOnApprovedTimeOff(int $userId, Carbon $day): bool
    {
        return DB::table('time_off_requests')
            ->where('user_id', $userId)
            ->where('status', 'approved')
            ->where('start_at', '<=', $day->copy()->endOfDay())
            ->where('end_at', '>=', $day->copy()->startOfDay())
            ->exists();
    }

    /* THE ROSTER IS THE ANSWER TO "WHEN WERE YOU DUE IN?" (2026-09-17)

       This file said, twice, "we have no shift schedule table" and fell back to asking
       whether somebody had clocked in on the same weekday in the last fortnight. There
       IS one — `shifts` (user, room, starts_at, ends_at, status), written by the staff
       calendar and filled by schedule:autofill — and using it fixes the complaint
       Anthony raised: a reminder that names no hours cannot be checked, and one based on
       a fortnight of habit chases people who are not due in yet.

       `starts_at` / `ends_at` are WALL CLOCK in the centre's own zone, stored naive, the
       same convention as a child's expected drop-off. They are never converted; they are
       read back in that zone. (SchedulingController compares them against plain local
       date strings, which only works because of this.)

       @return object|null the roster line for that local date, or null */
    private function scheduledShift(int $userId, ?int $centreId, string $localDate): ?object
    {
        $q = DB::table('shifts as s')
            ->where('s.user_id', $userId)
            ->whereIn('s.status', ['scheduled', 'active'])
            ->whereRaw('DATE(s.starts_at) = ?', [$localDate]);

        if ($centreId) {
            $q->join('rooms as r', 'r.id', '=', 's.room_id')->where('r.centre_id', $centreId);
        }

        return $q->orderBy('s.starts_at')->select('s.starts_at', 's.ends_at')->first();
    }

    /**
     * Is this person actually rostered — does their centre keep their schedule here?
     *
     * This decides whether an EMPTY day means "not working" or means nothing at all. An
     * agency that has never opened the staff calendar has no rows for anybody, and
     * reading that as "nobody is scheduled" would silence every reminder in the
     * building. So the roster only gets to say "you are not due in today" for somebody
     * who is on it in the first place.
     */
    private function hasRoster(int $userId, string $localDate): bool
    {
        $from = Carbon::parse($localDate)->subDays(28)->toDateString();
        $to = Carbon::parse($localDate)->addDays(28)->toDateString();

        return DB::table('shifts')
            ->where('user_id', $userId)
            ->whereIn('status', ['scheduled', 'active', 'completed'])
            ->whereRaw('DATE(starts_at) BETWEEN ? AND ?', [$from, $to])
            ->exists();
    }

    /** "7:00 AM to 6:00 PM", or null when there is no roster line to quote. */
    private function shiftWindow(?object $shift): ?string
    {
        if (! $shift) {
            return null;
        }
        $in = Carbon::parse($shift->starts_at)->format('g:i A');
        $out = $shift->ends_at ? Carbon::parse($shift->ends_at)->format('g:i A') : null;

        return $out ? ($in . ' to ' . $out) : ('from ' . $in);
    }

    /**
     * How long this person's day usually runs, in hours, from their own completed
     * entries over the last 28 days. Null when there is not enough history to say -
     * the caller then falls back to the flat minimum rather than inventing a number.
     */
    private function usualShiftHours(int $userId, Carbon $today): ?float
    {
        $rows = DB::table('time_punches')
            ->where('user_id', $userId)
            ->whereNotNull('punched_out_at')
            ->where('punched_in_at', '>=', $today->copy()->subDays(28))
            ->select('punched_in_at', 'punched_out_at')
            ->get();
        if ($rows->count() < 3) return null;

        $hours = [];
        foreach ($rows as $r) {
            $h = Carbon::parse($r->punched_in_at)->floatDiffInHours(Carbon::parse($r->punched_out_at));
            if ($h > 0.5 && $h < 18) $hours[] = $h;       // ignore mis-punches at both ends
        }
        if (count($hours) < 3) return null;
        sort($hours);
        return $hours[intdiv(count($hours), 2)];          // median, so one long day does not skew it
    }

    private function sendReminder(int $userId, int $centreId, string $to, ?string $firstName, string $subject, string $lead, ?string $shiftWindow = null): void
    {
        if ($this->dryRun) {
            $this->line("  [dry-run] would remind {$to} (user {$userId}) — {$subject}");
            return;
        }

        // Push + in-app notification so the reminder reaches the educator on the
        // APK, not only by email. FcmService handles device-token lookup and the
        // do-not-contact suppression for live agencies.
        try {
            \App\Support\Notify::write([
                'user_id' => $userId, 'type' => 'clock_reminder',
                'title' => $subject, 'body' => $lead,
                'data' => json_encode(['link' => '#dashboard']),
                'created_at' => now(),
            ]);
            app(\App\Services\FcmService::class)->sendToUser($userId, $subject, $lead, '#dashboard');
        } catch (\Throwable $e) {
            Log::warning('Clock reminder push failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }

        $agencyId = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
        $name = $firstName ?: 'there';
        $html = '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:600px;margin:0 auto;color:#111827;">'
            . '<h2 style="color:#1F6080;margin:0 0 12px;">⏰ Time clock reminder</h2>'
            . '<p style="font-size:15px;line-height:1.6;">Hi ' . e($name) . ',</p>'
            . '<p style="font-size:15px;line-height:1.6;">' . e($lead) . '</p>'
            . '<div style="background:#F3F8FB;border-left:4px solid #1F6080;border-radius:8px;padding:12px 16px;margin:16px 0;font-size:14px;line-height:1.6;">'
            . '<strong>Why clocking in and out matters:</strong>'
            . '<ul style="margin:8px 0 0;padding-left:18px;">'
            . '<li><strong>Compliance</strong> — licensing requires verifiable records of who was on the floor and when, to prove educator-to-child ratios.</li>'
            . '<li><strong>Payroll</strong> — your pay is calculated from recorded hours; a missing punch can mean a missed or short-paid shift.</li>'
            . '<li><strong>Reporting</strong> — attendance, CACFP and funding reports reconcile against staff hours, so accurate times keep the centre\'s claims correct.</li>'
            . '</ul></div>'
            /* WHAT WE THINK YOUR HOURS ARE, AND HOW TO CHANGE OUR MIND.

               A reminder that names no hours cannot be checked, so the only thing the
               reader can do is either clock in or ignore it — and the second one becomes
               a habit. Naming the rostered shift turns it into something correctable,
               and says plainly which two actions stop these arriving: fix the schedule,
               or book the day off before it happens. Approved leave already suppresses
               the reminder (isOnApprovedTimeOff), so that sentence is a promise the
               system actually keeps. (Anthony, 2026-09-17) */
            . '<div style="border:1px solid #E5E7EB;border-radius:8px;padding:12px 16px;margin:16px 0;font-size:14px;line-height:1.6;">'
            . '<strong>' . ($shiftWindow ? 'Your scheduled hours today' : 'Why you received this') . '</strong><br>'
            . ($shiftWindow
                ? 'You are rostered <strong>' . e($shiftWindow) . '</strong>. Reminders are sent against '
                  . 'this schedule, so if it does not match the hours you actually work, ask your director '
                  . 'or administrator to update it — once it is right, these reminders will line up with '
                  . 'your real day.'
                : 'You are not on the staff schedule for today, so this reminder was based on the days you '
                  . 'have recently worked. Ask your director or administrator to add your shifts to the '
                  . 'staff schedule and these reminders will follow your real hours instead.')
            . '<br><br>'
            . '<strong>Going to be off?</strong> Book it in advance — submit vacation, sick or personal '
            . 'leave in the app and, once it is approved, we stop sending these for those days '
            . 'automatically. Telling us in advance also keeps the room ratios and the day\'s planning '
            . 'right for everyone else.'
            . '</div>'
            . '<p style="font-size:14px;line-height:1.6;">Open the app and tap <strong>Clock in / out</strong> to fix this in a few seconds. Thank you!</p>'
            . '</div>';

        try {
            $svc = AgencyMailer::forAgency($agencyId ?: null);
            $from = $svc->fromAddress();
            $fn = $svc->fromName();
            /* $svc->html(), NOT $svc->mailer()->html(). The wrapper stamps
               X-KT-Agency-Id, which is what tells the suppression gate WHICH agency is
               sending. Without it the gate falls back to judging the send by every
               account that shares the recipient's address — and one of those belonging
               to an agency whose master switch is off cancels the message. That is how
               50 real iLearn emails died in September; this call site was still on the
               old path. (2026-09-17) */
            $svc->html($html, function ($msg) use ($to, $from, $fn, $subject) {
                $msg->to($to)->from($from, $fn)->subject($subject);
            });
        } catch (\Throwable $ex) {
            Log::warning('Clock reminder email failed', ['to' => $to, 'error' => $ex->getMessage()]);
        }
    }
}
