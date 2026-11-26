<?php

namespace App\Console\Commands;

use App\Support\SalesMeetings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * kt:sales-meetings — every 5 minutes: the sales@ calendar onto the leads, then the 30-minute reminders
 * (App\Support\SalesMeetings, 2026-11-04).
 *   --dry                      read and match only, change nothing
 *   --test-to=EMAIL --test-sms=PHONE   send the reminder for the next real meeting (or a sample) to these only
 */
class SalesMeetingsCommand extends Command
{
    protected $signature = 'kt:sales-meetings {--dry} {--test-to=} {--test-sms=}';
    protected $description = 'Sync sales@ calendar meetings to leads and send 30-minute reminders';

    public function handle(): int
    {
        if ($this->option('test-to') || $this->option('test-sms')) return $this->test();
        /* Meetings made on the Meetings screen (App\Support\MeetingReminders, 2026-11-13): first, and whatever the
           sales@ calendar does - they come from KiddieTrac's own list, not from Graph. */
        $this->line('portal ' . json_encode(\App\Support\MeetingReminders::due((bool) $this->option('dry'))));
        /* Lead meetings nobody marked, 30 min after the start: no-show + "pick a new time" (App\Support\NoShow, 2026-11-28).
           OFF unless PlatformSettings sales.noshow_auto = 1 - it returns at once otherwise. */
        try { $this->line('noshow ' . json_encode(\App\Support\NoShow::autoAsk((bool) $this->option('dry')))); } catch (\Throwable $e) { $this->line('noshow failed: ' . $e->getMessage()); }
        $s = SalesMeetings::sync((bool) $this->option('dry'));
        $this->line('sync ' . json_encode($s));
        if ($this->option('dry') || isset($s['error'])) return self::SUCCESS;
        $this->line('remind ' . json_encode(SalesMeetings::remindDue()));
        return self::SUCCESS;
    }

    private function test(): int
    {
        $m = DB::table('sales_calendar_meetings')->where('status', 'scheduled')->where('starts_at', '>', now())->orderBy('starts_at')->first();
        $lead = $m ? DB::table('sales_leads')->where('id', $m->lead_id)->first() : null;
        if (! $m || ! $lead) {
            $m = (object) ['id' => 0, 'subject' => 'Meet with Anthony (KiddieTrac)', 'email' => null, 'join_url' => 'https://teams.microsoft.com/l/meetup-join/sample',
                'location' => null, 'starts_at' => now()->addMinutes(30)->format('Y-m-d H:i:s'), 'ends_at' => now()->addMinutes(60)->format('Y-m-d H:i:s')];
            $lead = (object) ['id' => 0, 'name' => 'Anthony', 'email' => null, 'phone' => null, 'owner_phone' => null, 'owner_id' => 1];
        }
        // The test is addressed to the tester: their first name, not the lead's.
        $lead = (object) array_merge((array) $lead, ['name' => 'Anthony']);
        $this->line('meeting: ' . $m->subject . ' @ ' . SalesMeetings::whenEt($m->starts_at));
        if ($to = $this->option('test-to')) $this->line('email: ' . (SalesMeetings::email($m, $lead, $to) ? 'sent to ' . $to : 'FAILED'));
        if ($ph = $this->option('test-sms')) $this->line('sms: ' . SalesMeetings::sms($m, $lead, $ph));
        return self::SUCCESS;
    }
}
