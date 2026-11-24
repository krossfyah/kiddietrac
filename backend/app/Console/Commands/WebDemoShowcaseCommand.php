<?php

namespace App\Console\Commands;

use App\Support\WebDemoShowcase;
use Illuminate\Console\Command;

/** Link the website demo parent, educator and director to each other (App\Support\WebDemoShowcase). Silent; re-runnable. */
class WebDemoShowcaseCommand extends Command
{
    protected $signature = 'kt:web-demo-showcase {--dry-run : show what it would replace, change nothing}';
    protected $description = 'Write the linked demo story for the Brightwood website demo logins (chat, shifts, conference, field trip, medication, milestones)';

    public function handle(): int
    {
        $this->line(json_encode(WebDemoShowcase::run((bool) $this->option('dry-run'))));
        return self::SUCCESS;
    }
}
