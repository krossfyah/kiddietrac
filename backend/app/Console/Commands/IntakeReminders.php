<?php

namespace App\Console\Commands;

use App\Support\BookingMail;
use App\Support\BookingRunUp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * kt:intake-reminders — once a day, until the meeting (2026-10-23, Anthony: "if the user
 * didn't fill this out before hand ask them again when the meeting is booked ... and remind
 * them everyday up to the meeting date"). A confirmed website meeting whose prospect has not
 * sent the intake form gets the "Before we meet" email at most once per calendar day
 * (Eastern), and never after the meeting has started. Only while the proposal flow is on.
 */
class IntakeReminders extends Command
{
    protected $signature = 'kt:intake-reminders {--dry-run}';
    protected $description = 'Daily reminder to prospects to complete the intake form before their meeting';

    public function handle(): int
    {
        // THE RUN-UP SEQUENCE (2026-10-26, BookingRunUp), once Anthony has approved it.
        if (BookingRunUp::on()) return $this->runUp();
        if (! BookingMail::proposalFlow()) { $this->line('proposal flow is off'); return self::SUCCESS; }
        $today = now(BookingMail::TZ)->toDateString();
        $rows = DB::table('marketing_bookings as b')->leftJoin('prospect_intakes as i', 'i.booking_id', '=', 'b.id')
            ->where('b.status', 'booked')->where('b.starts_at', '>', now())->whereNull('i.id')
            ->where(fn ($q) => $q->whereNull('b.intake_reminded_on')->orWhere('b.intake_reminded_on', '<', $today))
            // the confirmation itself asked today; start the next day
            ->where(fn ($q) => $q->whereNull('b.decided_at')->orWhere('b.decided_at', '<', now(BookingMail::TZ)->startOfDay()->utc()))
            ->select('b.*')->limit(100)->get();
        foreach ($rows as $b) {
            if ($this->option('dry-run')) { $this->line('would remind #' . $b->id . ' ' . $b->email); continue; }
            $won = DB::table('marketing_bookings')->where('id', $b->id)->where(fn ($q) => $q->whereNull('intake_reminded_on')->orWhere('intake_reminded_on', '<', $today))
                ->update(['intake_reminded_on' => $today, 'intake_reminders' => DB::raw('intake_reminders + 1')]);
            if (! $won) continue;
            $ok = BookingMail::visitor($b, 'intake');
            $this->line('reminded #' . $b->id . ': ' . ($ok ? 'sent' : 'FAILED'));
        }
        return self::SUCCESS;
    }

    /**
     * One email a morning per confirmed meeting, never the same one twice (BookingRunUp::next decides which):
     * a film a day, then "see you tomorrow" and "see you today". The original intake reminder carries on only
     * when the films are used up and the form is still missing. Same once-a-day guard (intake_reminded_on), and
     * still nothing on the day the confirmation itself went out.
     */
    private function runUp(): int
    {
        $today = now(BookingMail::TZ)->toDateString();
        $rows = DB::table('marketing_bookings')->where('status', 'booked')->where('starts_at', '>', now())
            ->where(fn ($q) => $q->whereNull('intake_reminded_on')->orWhere('intake_reminded_on', '<', $today))
            ->where(fn ($q) => $q->whereNull('decided_at')->orWhere('decided_at', '<', now(BookingMail::TZ)->startOfDay()->utc()))
            ->limit(100)->get();
        foreach ($rows as $b) {
            $missing = ! DB::table('prospect_intakes')->where('booking_id', $b->id)->exists();
            $next = BookingRunUp::next($b, $missing);
            if (! $next) { if ($this->option('dry-run')) $this->line('nothing today for #' . $b->id); continue; }
            [$kind, $step] = $next;
            if ($this->option('dry-run')) { $this->line('would send #' . $b->id . ' ' . $kind . ($step ? ' ' . $step : '') . ' to ' . $b->email); continue; }
            $set = ['intake_reminded_on' => $today];
            if ($kind === 'intake') {
                $set['intake_reminders'] = DB::raw('intake_reminders + 1');
            } else {
                $sent = BookingRunUp::sent($b); $sent[] = BookingRunUp::key($b, $kind, $step);
                $set['run_up_sent'] = json_encode(array_values(array_unique($sent)));
            }
            $won = DB::table('marketing_bookings')->where('id', $b->id)->where(fn ($q) => $q->whereNull('intake_reminded_on')->orWhere('intake_reminded_on', '<', $today))->update($set);
            if (! $won) continue;
            $ok = BookingMail::visitor($b, $kind, null, null, $step);
            $this->line('run-up #' . $b->id . ' ' . $kind . ($step ? ' ' . $step : '') . ': ' . ($ok ? 'sent' : 'FAILED'));
        }
        return self::SUCCESS;
    }
}
