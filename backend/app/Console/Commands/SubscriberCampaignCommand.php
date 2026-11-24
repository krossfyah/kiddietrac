<?php

namespace App\Console\Commands;

use App\Support\SubscriberCampaign;
use Illuminate\Console\Command;

/** Preview a KiddieTrac subscriber email (App\Support\SubscriberCampaign): --count and --test only. Real sends: Sales -> Campaigns. */
class SubscriberCampaignCommand extends Command
{
    protected $signature = 'kiddietrac:subscriber-campaign {campaign : e.g. onetap} {--test= : Send one [TEST] copy to this address} {--send : Send to every subscriber} {--count : How many it would go to} {--if-approved : Only when PlatformSettings campaign.<key>.approved is 1} {--on= : Only on this date (America/Toronto), YYYY-MM-DD}';
    protected $description = 'Send a campaign to the KiddieTrac subscriber list';

    public function handle(): int
    {
        $key = (string) $this->argument('campaign');
        if (SubscriberCampaign::subject($key) === '') { $this->error('Unknown campaign'); return self::FAILURE; }
        if ($to = $this->option('test')) {
            $ok = SubscriberCampaign::sendOne($key, (string) $to, 'Anthony Hosein', true);
            $this->info($ok ? 'Test sent to ' . $to : 'Test failed (see the log)');
            return $ok ? self::SUCCESS : self::FAILURE;
        }
        // Scheduled sends (2026-10-27): a date and an approval gate, so a schedule set up ahead of the
        // approval sends nothing until Anthony says go, and nothing after its day.
        if ($on = $this->option('on')) {
            if (now('America/Toronto')->toDateString() !== $on) { $this->info('Not today (' . $on . ')'); return self::SUCCESS; }
        }
        if ($this->option('if-approved') && (string) \App\Support\PlatformSettings::get('campaign.' . $key . '.approved', '0') !== '1') {
            $this->info('Not approved yet: set campaign.' . $key . '.approved = 1 to send');
            return self::SUCCESS;
        }
        /* SEND IS RETIRED (2026-10-27, Anthony's rule): every real KiddieTrac send goes through Sales -> Campaigns, where a
           subscriber email is a campaign with a template (approve, schedule, tracking, one unsubscribe path). This
           command only previews (--count) and sends [TEST] copies (--test). */
        if ($this->option('send')) {
            $this->error('Sending from here is retired: open the campaign in Sales -> Campaigns and send or schedule it there.');
            return self::FAILURE;
        }
        $aud = SubscriberCampaign::audience($key);
        if (! $this->option('send')) { $this->info($aud->count() . ' subscribers would receive "' . SubscriberCampaign::subject($key) . '" (nothing sent; real sends go through Sales -> Campaigns)'); return self::SUCCESS; }
        $sent = 0; $failed = 0;
        foreach ($aud as $r) { SubscriberCampaign::sendOne($key, $r->email, (string) $r->name) ? $sent++ : $failed++; usleep(300000); }
        $this->info("$sent sent, $failed failed");
        return self::SUCCESS;
    }
}
