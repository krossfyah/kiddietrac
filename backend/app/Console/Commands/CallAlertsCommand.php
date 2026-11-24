<?php

namespace App\Console\Commands;

use App\Support\CallAlerts;
use App\Support\InboundCalls;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * kt:call-alerts — every minute: tell people about missed calls on the toll-free line
 * (App\Support\CallAlerts). A call counts once it is 3 minutes old with nobody having
 * answered and no voicemail left (a voicemail sends its own alert). Each call alerts once
 * (inbound_calls.notified_at); calls older than two hours are never alerted late.
 */
class CallAlertsCommand extends Command
{
    protected $signature = 'kt:call-alerts {--dry-run}';
    protected $description = 'Alert the right people about missed calls on the KiddieTrac line';

    public function handle(): int
    {
        $rows = DB::table('inbound_calls')->whereNull('notified_at')->whereNull('voicemail_id')
            ->whereIn('outcome', ['missed', 'ringing'])
            // 5 minutes: long enough for a voicemail (greeting + recording) to land first.
            ->where('created_at', '<', now()->subMinutes(5))
            ->where('created_at', '>=', now()->subHours(2))
            ->orderBy('id')->limit(50)->get();
        foreach ($rows as $r) {
            if ($this->option('dry-run')) { $this->line('would alert #' . $r->id); continue; }
            // Claim it first, so an overlapping run can never alert twice.
            $won = DB::table('inbound_calls')->where('id', $r->id)->whereNull('notified_at')->update(['notified_at' => now()]);
            if (! $won) continue;
            $n = CallAlerts::send('missed', $r->from_number, $r->department);
            $this->line('missed call #' . $r->id . ' -> ' . $n . ' people');
        }
        // Calls the alerts never apply to (answered, voicemail, older) are marked, so the
        // query above stays small.
        DB::table('inbound_calls')->whereNull('notified_at')->where(function ($q) {
            $q->whereIn('outcome', ['answered', 'voicemail'])->orWhere('created_at', '<', now()->subHours(2));
        })->update(['notified_at' => DB::raw('updated_at')]);
        return self::SUCCESS;
    }
}
