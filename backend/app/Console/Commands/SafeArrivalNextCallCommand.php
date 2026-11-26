<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\SafeArrivalCall;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * kiddietrac:safe-arrival-next — every minute. Rings the next guardian on a Safe Arrival
 * check once the agency's wait after the last unanswered call is up (2026-10-23, Anthony:
 * "give it a min or so each to call the next one"). SafeArrivalCall::callNext decides: it
 * skips a check with a call still live, one answered or resolved, one still inside its wait,
 * and one with no guardian left to ring - so running this often is harmless.
 */
final class SafeArrivalNextCallCommand extends Command
{
    protected $signature = 'kiddietrac:safe-arrival-next {--dry}';
    protected $description = 'Ring the next guardian for open Safe Arrival checks once the wait between calls is up';

    public function handle(): int
    {
        if (! Schema::hasTable('safe_arrival_checks') || ! Schema::hasTable('voice_calls')) return self::SUCCESS;
        $checks = DB::table('safe_arrival_checks')
            ->whereNotNull('call_started_at')->where('call_started_at', '>=', now()->subHours(6))
            ->whereNull('resolved_at')->whereNull('parent_response')
            ->get();
        foreach ($checks as $c) {
            if (\App\Support\Features::skips((int) $c->agency_id, 'sms', 'Safe Arrival calls')) continue;   // SMS & voice add-on (2026-11-26)
            /* ONLY A CHAIN STILL IN PROGRESS. The next guardian is rung shortly after the last
               unanswered call - never hours later, when "not signed in yet" is no longer news
               (the first deploy found seven of the morning's checks that would have been
               rung in the afternoon). And never once the child has been signed in. */
            $lastEnd = DB::table('voice_calls')->where('ref_type', 'safe_arrival_check')->where('ref_id', $c->id)
                ->max(DB::raw('COALESCE(ended_at, updated_at)'));
            if (! $lastEnd || \Illuminate\Support\Carbon::parse($lastEnd)->lt(now()->subMinutes(10))) continue;
            $in = DB::table('check_events')->where('child_id', $c->child_id)->where('event_type', 'check_in')
                ->where('occurred_at', '>=', $c->created_at)->exists();
            if ($in) continue;
            // Closed today (a same-day closure, 2026-11-06): nobody is expected, so no more calls. SafeArrivalCommand closes the check.
            if (\App\Support\Closures::isClosed((int) $c->centre_id, (string) $c->check_date)) { if ($this->option('dry')) $this->line("check {$c->id}: centre closed today, no call"); continue; }
            // Reported absent since (parent app, educator tile): SafeArrivalCommand closes the check within 5 minutes - don't ring meanwhile.
            if (DB::table('child_absences')->where('child_id', $c->child_id)->whereDate('absent_on', $c->check_date)->exists()) { if ($this->option('dry')) $this->line("check {$c->id}: reported absent, no call"); continue; }
            // Staff logged "running late, here by …" since (2026-11-06): no more calls until that time has passed.
            if (\App\Support\SafeArrival::runningLate($c)) { if ($this->option('dry')) $this->line("check {$c->id}: running late, no call"); continue; }
            // Counted in a headcount since (2026-11-03): callNext would refuse too; say so in a dry run.
            if (! \App\Support\SafeArrival::stillExpected($c)) { if ($this->option('dry')) $this->line("check {$c->id}: no longer expected today, no call"); continue; }
            if (\App\Support\SafeArrival::checkCounted($c)) { if ($this->option('dry')) $this->line("check {$c->id}: counted in a headcount, no call"); continue; }
            if ($this->option('dry')) { $this->line("check {$c->id}: would try the next guardian"); continue; }
            try {
                if (SafeArrivalCall::callNext($c)) $this->line("check {$c->id}: rang the next guardian");
            } catch (\Throwable $e) {
                $this->warn("check {$c->id}: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
