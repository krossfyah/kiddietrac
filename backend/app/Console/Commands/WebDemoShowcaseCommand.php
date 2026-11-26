<?php

namespace App\Console\Commands;

use App\Support\WebDemoShowcase;
use Illuminate\Console\Command;

/**
 * Link the website demo parent, educator, director and admin to each other (App\Support\WebDemoShowcase). Silent; re-runnable.
 * --set picks the login set in PublicDemo::sets(): kiddietrac = Brightwood (agency 25), white_label = Starlight (agency 26).
 */
class WebDemoShowcaseCommand extends Command
{
    protected $signature = 'kt:web-demo-showcase {--set=kiddietrac : kiddietrac (Brightwood), white_label (Starlight) or all} {--dry-run : show what it would replace, change nothing}';
    protected $description = 'Write the linked demo story for a website demo login set (chat, shifts, conference, field trip, medication, milestones, Play Spark, notes, tasks, bell)';

    public function handle(): int
    {
        $set = (string) $this->option('set');
        $sets = $set === 'all' ? ['kiddietrac', 'white_label'] : [$set];
        foreach ($sets as $s) {
            $this->line($s . ': ' . json_encode(WebDemoShowcase::run((bool) $this->option('dry-run'), $s)));
        }
        return self::SUCCESS;
    }
}
