<?php

namespace App\Console\Commands;

use App\Support\PersonalAnnouncements;
use Illuminate\Console\Command;

/** New invoices and payslips become personal announcements (App\Support\PersonalAnnouncements). Every 2 minutes. */
class PersonalAnnouncementsCommand extends Command
{
    protected $signature = 'kt:personal-announcements {--dry-run : count only, write nothing}';
    protected $description = 'Turn new invoices and payslips into personal announcements for the people they belong to';

    public function handle(): int
    {
        $this->line(json_encode(PersonalAnnouncements::run((bool) $this->option('dry-run'))));
        return self::SUCCESS;
    }
}
