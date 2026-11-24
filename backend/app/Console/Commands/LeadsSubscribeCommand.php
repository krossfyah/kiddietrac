<?php

namespace App\Console\Commands;

use App\Support\LeadWelcome;
use Illuminate\Console\Command;

/** Puts every open lead with an email on the subscriber list (no email is sent). 2026-10-23. */
class LeadsSubscribeCommand extends Command
{
    protected $signature = 'kt:leads-subscribe';
    protected $description = 'Add leads with an email to the website subscriber list (never re-subscribes anyone who unsubscribed)';

    public function handle(): int
    {
        $this->info(LeadWelcome::subscribeAllLeads() . ' lead(s) added to the subscriber list.');
        return self::SUCCESS;
    }
}
