<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * v22p98 — Staff clock-in/out reminders.
 *
 * Educators/directors record their hours via the time clock (time_entries).
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
    protected $signature = 'staff:clock-reminders {--mode=all : clock_in | clock_out | all} {--dry-run : list who would be reminded without sending}';
    protected $description = 'Email educators/directors who forgot to clock in or out (compliance, payroll, reporting).';

    private bool $dryRun = false;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
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
        $open = DB::table('time_entries as t')
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->whereNull('t.clocked_out_at')
            ->whereDate('t.clocked_in_at', $today)
            // only nudge once they've plausibly left (>= 6h in)
            ->where('t.clocked_in_at', '<=', Carbon::now()->subHours(6))
            ->whereNotNull('u.email')->whereNull('u.deleted_at')
            ->select('t.user_id', 't.centre_id', 't.clocked_in_at', 'u.email', 'u.first_name')
            ->get();

        $n = 0;
        foreach ($open as $e) {
            $inAt = Carbon::parse($e->clocked_in_at)->format('g:i A');
            $this->emailReminder(
                (int) $e->centre_id, $e->email, $e->first_name,
                'Reminder: you\'re still clocked in',
                "You clocked in at {$inAt} today but haven't clocked out yet. Please clock out so your hours are recorded correctly — or, if you left already, log your out time from the time clock.",
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
        $dow = (int) $today->dayOfWeekIso; // 1=Mon..7=Sun
        if ($dow >= 6) return 0;           // skip weekends

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
            $clockedToday = DB::table('time_entries')
                ->where('user_id', $s->user_id)->whereDate('clocked_in_at', $today)->exists();
            if ($clockedToday) continue;

            // Did they work this same weekday in the last 14 days? (regular pattern)
            $worksThisWeekday = DB::table('time_entries')
                ->where('user_id', $s->user_id)
                ->where('clocked_in_at', '>=', $today->copy()->subDays(14))
                ->whereRaw('WEEKDAY(clocked_in_at) = ?', [$dow - 1]) // MySQL WEEKDAY: 0=Mon
                ->exists();
            if (! $worksThisWeekday) continue;

            $this->emailReminder(
                (int) $s->centre_id, $s->email, $s->first_name,
                'Reminder: don\'t forget to clock in',
                "We noticed you're not clocked in today. If you're working, please clock in now so your hours are captured. If you're off today, you can ignore this.",
            );
            $n++;
        }
        return $n;
    }

    private function emailReminder(int $centreId, string $to, ?string $firstName, string $subject, string $lead): void
    {
        if ($this->dryRun) {
            $this->line("  [dry-run] would email {$to} — {$subject}");
            return;
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
            . '<p style="font-size:14px;line-height:1.6;">Open the app and tap <strong>Clock in / out</strong> to fix this in a few seconds. Thank you!</p>'
            . '</div>';

        try {
            $svc = AgencyMailer::forAgency($agencyId ?: null);
            $m = $svc->mailer();
            $from = $svc->fromAddress();
            $fn = $svc->fromName();
            $m->html($html, function ($msg) use ($to, $from, $fn, $subject) {
                $msg->to($to)->from($from, $fn)->subject($subject);
            });
        } catch (\Throwable $ex) {
            Log::warning('Clock reminder email failed', ['to' => $to, 'error' => $ex->getMessage()]);
        }
    }
}
